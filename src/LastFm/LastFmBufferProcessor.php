<?php

namespace App\LastFm;

use App\Entity\LastFmBufferedScrobble;
use App\Entity\LastFmImportTrack;
use App\Entity\RunHistory;
use App\Navidrome\NavidromeRepository;
use App\Repository\LastFmBufferedScrobbleRepository;
use App\Repository\LastFmMatchCacheRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Drains the lastfm_import_buffer: for each row, runs the matching cascade
 * ({@see ScrobbleMatcher}), inserts a Navidrome scrobble when a match is
 * found and not already present (±N seconds dedup), persists an audit row
 * in lastfm_import_track tied to the current RunHistory, then deletes the
 * buffer row.
 *
 * This is the only place that writes to Navidrome — call it with Navidrome
 * stopped (the caller owns the pre-flight via NavidromeContainerManager).
 *
 * Crash-safety contract — each batch of {@see self::BATCH_SIZE} buffer rows
 * is processed atomically:
 *  1. Open `BEGIN IMMEDIATE` on the Navidrome connection.
 *  2. INSERT every match'ed scrobble inside the transaction.
 *  3. COMMIT.
 *  4. Only THEN persist `LastFmImportTrack` audit rows, DELETE the buffer
 *     entries, flush app-DB.
 *
 * If anything in steps 1-3 throws (PHP crash, kill -9 mid-INSERT, etc.) the
 * Navidrome rollback discards the partial writes ; the audit rows haven't
 * been persisted yet ; and the buffer rows haven't been deleted, so a re-run
 * picks them up. Dedup via {@see NavidromeRepository::scrobbleExistsNear()}
 * keeps the re-run idempotent. The post-commit step (4) is on the app-DB
 * which can fail independently — the resulting drift is recovered on
 * re-run by the same dedup mechanism.
 *
 * Idempotent: a buffer row processed twice ends up with one Navidrome
 * scrobble + one audit row, then disappears from the buffer.
 */
class LastFmBufferProcessor
{
    private const int BATCH_SIZE = 100;

    private LoggerInterface $logger;

    public function __construct(
        private readonly LastFmBufferedScrobbleRepository $bufferRepo,
        private readonly ScrobbleMatcher $matcher,
        private readonly NavidromeRepository $navidrome,
        private readonly EntityManagerInterface $em,
        ?LoggerInterface $logger = null,
        private readonly ?LastFmMatchCacheRepository $cacheRepository = null,
        private readonly int $cacheTtlDays = 30,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param callable(int $considered, int $inserted, int $duplicates, int $unmatched): void $progress
     */
    public function process(
        int $limit = 0,
        bool $dryRun = false,
        int $toleranceSeconds = 60,
        ?RunHistory $auditRun = null,
        ?callable $progress = null,
    ): ProcessReport {
        if (!$this->navidrome->hasScrobblesTable()) {
            throw new \RuntimeException(
                'The Navidrome scrobbles table does not exist. Upgrade Navidrome to >= 0.55.',
            );
        }
        $userId = $this->navidrome->resolveUserId();

        // Same auto-purge as LastFmRematchService — stale negatives let
        // the cascade re-try couples that may have become matchable since
        // the cache row was written.
        $this->cacheRepository?->purgeStale($this->cacheTtlDays);

        $report = new ProcessReport();
        $appConnection = $this->em->getConnection();
        /** @var list<array{
         *     bufferId: int,
         *     artist: string,
         *     title: string,
         *     album: ?string,
         *     mbid: ?string,
         *     playedAt: \DateTimeImmutable,
         *     status: string,
         *     matchedId: ?string,
         *     willInsertScrobble: bool,
         * }> $pending
         */
        $pending = [];

        try {
            foreach ($this->bufferRepo->streamAll($limit) as $buffered) {
                /** @var LastFmBufferedScrobble $buffered */
                $report->considered++;

                $scrobble = new LastFmScrobble(
                    artist: $buffered->getArtist(),
                    title: $buffered->getTitle(),
                    album: $buffered->getAlbum() ?? '',
                    mbid: $buffered->getMbid(),
                    playedAt: $buffered->getPlayedAt(),
                );

                $result = $this->matcher->match($scrobble);
                $this->bumpCacheCounters($report, $result);

                if ($result->status === MatchResult::STATUS_SKIPPED) {
                    $report->skipped++;
                    $status = LastFmImportTrack::STATUS_SKIPPED;
                    $matchedId = null;
                    $willInsert = false;
                    $this->logger->debug('Buffer skipped (alias): {artist} — {title}', [
                        'artist' => $buffered->getArtist(),
                        'title' => $buffered->getTitle(),
                    ]);
                } elseif ($result->mediaFileId === null) {
                    $report->unmatched++;
                    $status = LastFmImportTrack::STATUS_UNMATCHED;
                    $matchedId = null;
                    $willInsert = false;
                } elseif (
                    $this->navidrome->scrobbleExistsNear(
                        $userId,
                        $result->mediaFileId,
                        $buffered->getPlayedAt(),
                        $toleranceSeconds,
                    )
                    || $this->pendingBatchHasNearScrobble(
                        $pending,
                        $result->mediaFileId,
                        $buffered->getPlayedAt(),
                        $toleranceSeconds,
                    )
                ) {
                    $report->duplicates++;
                    $status = LastFmImportTrack::STATUS_DUPLICATE;
                    $matchedId = $result->mediaFileId;
                    $willInsert = false;
                } else {
                    $report->inserted++;
                    $status = LastFmImportTrack::STATUS_INSERTED;
                    $matchedId = $result->mediaFileId;
                    $willInsert = !$dryRun;
                }

                if (!$dryRun) {
                    $pending[] = [
                        'bufferId' => $buffered->getId(),
                        'artist' => $buffered->getArtist(),
                        'title' => $buffered->getTitle(),
                        'album' => $buffered->getAlbum(),
                        'mbid' => $buffered->getMbid(),
                        'playedAt' => $buffered->getPlayedAt(),
                        'status' => $status,
                        'matchedId' => $matchedId,
                        'willInsertScrobble' => $willInsert,
                    ];
                }

                // Detach the buffered entity now that we have a snapshot of
                // its fields — keeps the Doctrine identity map small even
                // mid-batch on a 100k-row buffer.
                if ($this->em->contains($buffered)) {
                    $this->em->detach($buffered);
                }

                if (!$dryRun && count($pending) >= self::BATCH_SIZE) {
                    $this->flushBatch($pending, $userId, $auditRun, $appConnection);
                    $pending = [];
                }

                if ($progress !== null && $report->considered % 50 === 0) {
                    $progress($report->considered, $report->inserted, $report->duplicates, $report->unmatched);
                }
            }

            if (!$dryRun) {
                $this->flushBatch($pending, $userId, $auditRun, $appConnection);
            }

            if ($progress !== null) {
                $progress($report->considered, $report->inserted, $report->duplicates, $report->unmatched);
            }
        } finally {
            // Belt-and-braces: merge the WAL into the main DB and release the
            // file lock before Navidrome reopens it. Even on a clean run we
            // do not want to leave a non-empty WAL behind, since Navidrome
            // would have to recover it on its own next start.
            $this->navidrome->walCheckpointTruncate();
            $this->navidrome->closeWriteConnection();
        }

        return $report;
    }

    /**
     * Cross-check the in-flight pending batch for a near-scrobble that the
     * current iteration would otherwise duplicate. `scrobbleExistsNear`
     * only sees what previous batches committed — without this helper, two
     * buffer rows for the same scrobble (same media_file + ±tolerance
     * seconds, e.g. a Last.fm fetch retry that double-buffered) would both
     * be marked « inserted » and end up doubly inserted in Navidrome.
     *
     * @param list<array{
     *     bufferId: int,
     *     artist: string,
     *     title: string,
     *     album: ?string,
     *     mbid: ?string,
     *     playedAt: \DateTimeImmutable,
     *     status: string,
     *     matchedId: ?string,
     *     willInsertScrobble: bool,
     * }> $pending
     */
    private function pendingBatchHasNearScrobble(
        array $pending,
        string $mediaFileId,
        \DateTimeImmutable $playedAt,
        int $toleranceSeconds,
    ): bool {
        $ts = $playedAt->getTimestamp();
        foreach ($pending as $row) {
            if (!$row['willInsertScrobble']) {
                continue;
            }
            if ($row['matchedId'] !== $mediaFileId) {
                continue;
            }
            if (abs($row['playedAt']->getTimestamp() - $ts) <= $toleranceSeconds) {
                return true;
            }
        }

        return false;
    }

    /**
     * Flush one transactional batch :
     *   1. BEGIN IMMEDIATE on Navidrome
     *   2. INSERT every pending scrobble that should land
     *   3. COMMIT — if anything throws, ROLLBACK and re-raise (the buffer
     *      rows and audits stay un-persisted, so a retry resumes cleanly).
     *   4. Persist audit rows, DELETE buffer rows, flush app-DB.
     *
     * @param  list<array{
     *     bufferId: int,
     *     artist: string,
     *     title: string,
     *     album: ?string,
     *     mbid: ?string,
     *     playedAt: \DateTimeImmutable,
     *     status: string,
     *     matchedId: ?string,
     *     willInsertScrobble: bool,
     * }> $pending
     */
    private function flushBatch(
        array $pending,
        string $userId,
        ?RunHistory $auditRun,
        Connection $appConnection,
    ): void {
        if ($pending === []) {
            return;
        }

        $this->navidrome->beginWriteTransaction();
        try {
            foreach ($pending as $row) {
                if ($row['willInsertScrobble'] && $row['matchedId'] !== null) {
                    $this->navidrome->insertScrobble($userId, $row['matchedId'], $row['playedAt']);
                }
            }
            $this->navidrome->commitWrite();
        } catch (\Throwable $e) {
            try {
                $this->navidrome->rollbackWrite();
            } catch (\Throwable) {
                // Already in trouble — original exception wins. The WAL
                // checkpoint + close in the outer finally will run.
            }
            throw $e;
        }

        // Navidrome side committed — we can now flush the audit + cleanup
        // the buffer. If THIS step crashes, the user re-runs and the
        // ±toleranceSeconds dedup catches the already-inserted scrobbles
        // as duplicates ; no double-write.
        $persistedAudits = [];
        if ($auditRun !== null) {
            foreach ($pending as $row) {
                $importTrack = new LastFmImportTrack(
                    runHistory: $auditRun,
                    artist: $row['artist'],
                    title: $row['title'],
                    album: $row['album'],
                    mbid: $row['mbid'],
                    playedAt: $row['playedAt'],
                    status: $row['status'],
                    matchedMediaFileId: $row['matchedId'],
                );
                $this->em->persist($importTrack);
                $persistedAudits[] = $importTrack;
            }
        }

        // Bulk DELETE — buffer ids are integers (entity primary key), safe
        // to inline. Avoids a 100-deep IN-clause via DBAL array param type.
        $bufferIds = array_map(static fn (array $row): int => $row['bufferId'], $pending);
        $appConnection->executeStatement(
            'DELETE FROM lastfm_import_buffer WHERE id IN (' . implode(',', $bufferIds) . ')',
        );

        $this->em->flush();

        // Detach the just-flushed audits + the matcher's pending cache rows
        // so the identity map does not grow over the run.
        foreach ($persistedAudits as $track) {
            if ($this->em->contains($track)) {
                $this->em->detach($track);
            }
        }
        $this->cacheRepository?->detachPending();
    }

    private function bumpCacheCounters(ProcessReport $report, MatchResult $result): void
    {
        match ($result->cacheStatus) {
            MatchResult::CACHE_HIT_POSITIVE => $report->cacheHitsPositive++,
            MatchResult::CACHE_HIT_NEGATIVE => $report->cacheHitsNegative++,
            MatchResult::CACHE_MISS => $report->cacheMisses++,
            default => null,
        };
    }
}
