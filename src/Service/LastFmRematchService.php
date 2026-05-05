<?php

namespace App\Service;

use App\Entity\LastFmImportTrack;
use App\LastFm\LastFmScrobble;
use App\LastFm\MatchResult;
use App\LastFm\RematchReport;
use App\LastFm\ScrobbleMatcher;
use App\Navidrome\NavidromeRepository;
use App\Repository\LastFmImportTrackRepository;
use App\Repository\LastFmMatchCacheRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Re-applies the matching cascade ({@see ScrobbleMatcher}) on rows of
 * `lastfm_import_track` previously stored as `unmatched`. When a row finds
 * a match, the corresponding scrobble is inserted in Navidrome (with the
 * usual ±N seconds dedup) and the row's status flips to `inserted` /
 * `duplicate` / `skipped`.
 *
 * Crash-safety contract — each batch of {@see self::BATCH_SIZE} tracks goes
 * through a Navidrome transaction (`BEGIN IMMEDIATE` / `COMMIT`). Status +
 * matchedMediaFileId mutations on the managed `LastFmImportTrack` entities
 * are deferred until AFTER the Navidrome commit — so a crash mid-batch
 * (kill -9, exception, OOM) rolls back the partial Navidrome writes AND
 * leaves the audit rows in their original `unmatched` state, ready for a
 * clean retry.
 *
 * Idempotent: calling rematch twice in a row is a no-op past the first
 * call. Garde-fou via {@see NavidromeRepository::scrobbleExistsNear()}.
 */
class LastFmRematchService
{
    private const int BATCH_SIZE = 100;

    private LoggerInterface $logger;

    public function __construct(
        private readonly LastFmImportTrackRepository $trackRepo,
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
     * @param ?callable(int $considered, int $matched, int $stillUnmatched): void $progress
     */
    public function rematch(
        ?int $runId = null,
        int $limit = 0,
        bool $dryRun = false,
        int $toleranceSeconds = 60,
        bool $random = false,
        ?callable $progress = null,
    ): RematchReport {
        if (!$this->navidrome->hasScrobblesTable()) {
            throw new \RuntimeException(
                'The Navidrome scrobbles table does not exist. Upgrade Navidrome to >= 0.55.',
            );
        }
        $userId = $this->navidrome->resolveUserId();

        // Same auto-purge as the buffer processor — stale negatives let
        // the cascade re-try unmatched couples that may have become
        // matchable since the cache row was written.
        $this->cacheRepository?->purgeStale($this->cacheTtlDays);

        $report = new RematchReport();
        /** @var list<array{
         *     track: LastFmImportTrack,
         *     statusToSet: ?string,
         *     matchedIdToSet: ?string,
         *     willInsertScrobble: bool,
         *     mediaFileId: ?string,
         * }> $pending
         */
        $pending = [];

        try {
            foreach ($this->trackRepo->streamUnmatched($runId, $limit, $random) as $track) {
                /** @var LastFmImportTrack $track */
                $report->considered++;

                $scrobble = new LastFmScrobble(
                    artist: $track->getArtist(),
                    title: $track->getTitle(),
                    album: $track->getAlbum() ?? '',
                    mbid: $track->getMbid(),
                    playedAt: $track->getPlayedAt(),
                );

                $result = $this->matcher->match($scrobble);
                match ($result->cacheStatus) {
                    MatchResult::CACHE_HIT_POSITIVE => $report->cacheHitsPositive++,
                    MatchResult::CACHE_HIT_NEGATIVE => $report->cacheHitsNegative++,
                    MatchResult::CACHE_MISS => $report->cacheMisses++,
                    default => null,
                };

                $statusToSet = null;
                $matchedIdToSet = null;
                $willInsertScrobble = false;

                if ($result->status === MatchResult::STATUS_SKIPPED) {
                    $report->skipped++;
                    $statusToSet = LastFmImportTrack::STATUS_SKIPPED;
                    $this->logger->debug('Rematch skipped (alias): {artist} — {title}', [
                        'artist' => $track->getArtist(),
                        'title' => $track->getTitle(),
                    ]);
                } elseif ($result->mediaFileId === null) {
                    $report->stillUnmatched++;
                    // Leave the row as `unmatched` — no mutations queued.
                } elseif (
                    $this->navidrome->scrobbleExistsNear($userId, $result->mediaFileId, $track->getPlayedAt(), $toleranceSeconds)
                    || $this->pendingBatchHasNearScrobble($pending, $result->mediaFileId, $track->getPlayedAt(), $toleranceSeconds)
                ) {
                    $report->matchedAsDuplicate++;
                    $statusToSet = LastFmImportTrack::STATUS_DUPLICATE;
                    $matchedIdToSet = $result->mediaFileId;
                    $this->logger->debug('Rematch found duplicate: {artist} — {title}', [
                        'artist' => $track->getArtist(),
                        'title' => $track->getTitle(),
                    ]);
                } else {
                    $report->matchedAsInserted++;
                    $statusToSet = LastFmImportTrack::STATUS_INSERTED;
                    $matchedIdToSet = $result->mediaFileId;
                    $willInsertScrobble = !$dryRun;
                    $this->logger->debug('Rematch inserted: {artist} — {title}', [
                        'artist' => $track->getArtist(),
                        'title' => $track->getTitle(),
                    ]);
                }

                if (!$dryRun) {
                    $pending[] = [
                        'track' => $track,
                        'statusToSet' => $statusToSet,
                        'matchedIdToSet' => $matchedIdToSet,
                        'willInsertScrobble' => $willInsertScrobble,
                        'mediaFileId' => $result->mediaFileId,
                    ];
                }

                if (!$dryRun && count($pending) >= self::BATCH_SIZE) {
                    $this->flushBatch($pending, $userId);
                    $pending = [];
                }

                if ($progress !== null && $report->considered % 50 === 0) {
                    $progress(
                        $report->considered,
                        $report->matchedAsInserted + $report->matchedAsDuplicate + $report->skipped,
                        $report->stillUnmatched,
                    );
                }
            }

            if (!$dryRun) {
                $this->flushBatch($pending, $userId);
            }

            if ($progress !== null) {
                $progress(
                    $report->considered,
                    $report->matchedAsInserted + $report->matchedAsDuplicate + $report->skipped,
                    $report->stillUnmatched,
                );
            }
        } finally {
            $this->navidrome->walCheckpointTruncate();
            $this->navidrome->closeWriteConnection();
        }

        return $report;
    }

    /**
     * Cross-check the in-flight pending batch for a near-scrobble that the
     * current iteration would otherwise duplicate. Without this, two unmatched
     * rows for the SAME scrobble (same artist/title/playedAt, e.g. produced
     * by two distinct imports) would both pass the committed-state check —
     * `scrobbleExistsNear` only sees what previous batches have committed —
     * and end up doubly inserted in Navidrome at COMMIT time.
     *
     * @param list<array{
     *     track: LastFmImportTrack,
     *     statusToSet: ?string,
     *     matchedIdToSet: ?string,
     *     willInsertScrobble: bool,
     *     mediaFileId: ?string,
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
            if ($row['mediaFileId'] !== $mediaFileId) {
                continue;
            }
            if (abs($row['track']->getPlayedAt()->getTimestamp() - $ts) <= $toleranceSeconds) {
                return true;
            }
        }

        return false;
    }

    /**
     * Flush one transactional batch :
     *   1. BEGIN IMMEDIATE on Navidrome.
     *   2. INSERT every match'd-as-inserted row inside the transaction.
     *   3. COMMIT — on error, ROLLBACK and re-raise. The mutations on
     *      $track entities have NOT been applied yet, so the rows stay
     *      `unmatched` and a retry will reprocess them.
     *   4. Apply the queued status/matchedId mutations on the entities,
     *      flush app-DB, detach.
     *
     * @param list<array{
     *     track: LastFmImportTrack,
     *     statusToSet: ?string,
     *     matchedIdToSet: ?string,
     *     willInsertScrobble: bool,
     *     mediaFileId: ?string,
     * }> $pending
     */
    private function flushBatch(array $pending, string $userId): void
    {
        if ($pending === []) {
            return;
        }

        $this->navidrome->beginWriteTransaction();
        try {
            foreach ($pending as $row) {
                if ($row['willInsertScrobble'] && $row['mediaFileId'] !== null) {
                    $this->navidrome->insertScrobble($userId, $row['mediaFileId'], $row['track']->getPlayedAt());
                }
            }
            $this->navidrome->commitWrite();
        } catch (\Throwable $e) {
            try {
                $this->navidrome->rollbackWrite();
            } catch (\Throwable) {
                // Already in trouble — original exception wins.
            }
            throw $e;
        }

        // Navidrome side committed — now safe to mutate the audit entities.
        foreach ($pending as $row) {
            if ($row['statusToSet'] !== null) {
                $row['track']->setStatus($row['statusToSet']);
            }
            if ($row['matchedIdToSet'] !== null) {
                $row['track']->setMatchedMediaFileId($row['matchedIdToSet']);
            }
        }
        $this->em->flush();

        // Detach the just-flushed audits + the matcher's pending cache rows
        // so the identity map does not grow over the run.
        foreach ($pending as $row) {
            if ($this->em->contains($row['track'])) {
                $this->em->detach($row['track']);
            }
        }
        $this->cacheRepository?->detachPending();
    }
}
