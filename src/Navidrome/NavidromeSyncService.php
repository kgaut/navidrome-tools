<?php

namespace App\Navidrome;

use App\Entity\RunHistory;
use App\Entity\ScrobbleSync;
use App\LastFm\LastFmApiException;
use App\LastFm\LastFmRequestException;
use App\LastFm\LastFmScrobble;
use App\LastFm\MatchResult;
use App\LastFm\ScrobbleMatcher;
use App\Repository\ScrobbleSyncRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Matches pending scrobble_sync rows for the Navidrome target and writes
 * the matched scrobbles into the Navidrome `scrobbles` table.
 *
 * Pre-conditions (enforced by the caller):
 *  - Navidrome must be stopped (write to its SQLite while it runs corrupts WAL)
 *  - A backup of navidrome.db should be taken before the first write
 *
 * Crash safety: each batch of BATCH_SIZE rows is committed atomically via
 * BEGIN IMMEDIATE → INSERT → COMMIT. Doctrine ORM flush happens only after the
 * Navidrome commit, so a crash between the two steps leaves the scrobble_sync
 * row in `pending` and will be retried on the next run (dedup via
 * scrobbleExistsNear).
 *
 * Long runs additionally take periodic « checkpoint » backups every
 * `$backupIntervalSeconds` (WAL checkpoint + fresh snapshot). A deployment
 * whose wrapper restores the latest backup on failure then only loses the
 * work since the last checkpoint instead of the whole run.
 */
class NavidromeSyncService
{
    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly ScrobbleSyncRepository $syncRepo,
        private readonly ScrobbleMatcher $matcher,
        private readonly NavidromeRepository $navidrome,
        private readonly NavidromeDbBackup $backup,
        private readonly EntityManagerInterface $em,
        private readonly int $backupIntervalSeconds = 600,
        private readonly int $batchSize = self::BATCH_SIZE,
        private readonly int $maxConsecutiveApiErrors = 25,
    ) {
    }

    public function process(
        int $limit = 0,
        bool $dryRun = false,
        int $toleranceSeconds = 60,
        ?RunHistory $run = null,
        ?callable $progress = null,
    ): NavidromeSyncReport {
        if (!$this->navidrome->hasScrobblesTable()) {
            throw new \RuntimeException('Navidrome scrobbles table not found. Upgrade Navidrome to >= 0.55.');
        }

        $report = new NavidromeSyncReport();
        $report->dryRun = $dryRun;

        // 1. Prepare pending rows for any scrobbles not yet in scrobble_sync.
        $report->prepared = $this->syncRepo->prepareForTarget(ScrobbleSync::TARGET_NAVIDROME);

        $userId = $this->navidrome->resolveUserId();
        $backupTaken = false;
        $lastBackupAt = 0;
        $consecutiveApiErrors = 0;

        /** @var list<array{sync: ScrobbleSync, matchedId: ?string, status: string, strategy: ?string, willInsert: bool}> $batch */
        $batch = [];

        try {
            foreach ($this->syncRepo->streamPending(ScrobbleSync::TARGET_NAVIDROME, $limit) as $sync) {
                $report->considered++;
                $scrobble = $sync->getScrobble();

                $lfmScrobble = new LastFmScrobble(
                    artist: $scrobble->getArtist(),
                    title: $scrobble->getTitle(),
                    album: $scrobble->getAlbum() ?? '',
                    mbid: $scrobble->getMbidTrack(),
                    playedAt: $scrobble->getPlayedAt(),
                );

                try {
                    $result = $this->matcher->match($lfmScrobble);
                    $consecutiveApiErrors = 0;
                } catch (LastFmRequestException | LastFmApiException $e) {
                    // Transient Last.fm failure (timeout, empty body, rate
                    // limit…). Don't poison the cache or abort the whole run:
                    // leave this scrobble pending and move on — it'll be
                    // retried next time. A burst of consecutive failures means
                    // Last.fm is down, so stop gracefully (committed work kept).
                    $report->apiErrors++;
                    if (++$consecutiveApiErrors >= $this->maxConsecutiveApiErrors) {
                        $report->abortedOnApiErrors = true;
                        break;
                    }
                    continue;
                }

                [$status, $matchedId, $strategy, $willInsert] = $this->classify(
                    $result,
                    $userId,
                    $scrobble->getPlayedAt(),
                    $toleranceSeconds,
                    $batch,
                    $dryRun,
                    $report,
                );

                if (!$dryRun) {
                    $batch[] = [
                        'sync' => $sync,
                        'matchedId' => $matchedId,
                        'status' => $status,
                        'strategy' => $strategy,
                        'willInsert' => $willInsert,
                    ];
                }

                if (!$dryRun && count($batch) >= $this->batchSize) {
                    if (!$backupTaken) {
                        $report->backupPath = $this->backup->backup();
                        $backupTaken = true;
                        $lastBackupAt = time();
                    }
                    $this->flushBatch($batch, $userId, $run);
                    $batch = [];
                    $this->maybeIntermediateBackup($report, $lastBackupAt);
                }

                if ($progress !== null && $report->considered % 50 === 0) {
                    $progress($report->considered, $report->matched, $report->duplicates, $report->unmatched);
                }
            }

            if (!$dryRun && $batch !== []) {
                if (!$backupTaken) {
                    $report->backupPath = $this->backup->backup();
                    $lastBackupAt = time();
                }
                $this->flushBatch($batch, $userId, $run);
            }

            if ($progress !== null) {
                $progress($report->considered, $report->matched, $report->duplicates, $report->unmatched);
            }
        } finally {
            $this->navidrome->walCheckpointTruncate();
            $this->navidrome->closeWriteConnection();
        }

        return $report;
    }

    /**
     * Take a « checkpoint » backup if at least `$backupIntervalSeconds` have
     * elapsed since the last one. Called right after a committed batch flush,
     * so the WAL is checkpoint-truncated into the main DB first to make the
     * snapshot self-contained. A negative interval disables it; `0` backs up
     * after every batch. Updates `$lastBackupAt` in place.
     */
    private function maybeIntermediateBackup(NavidromeSyncReport $report, int &$lastBackupAt): void
    {
        if ($this->backupIntervalSeconds < 0 || $lastBackupAt === 0) {
            return;
        }
        if ((time() - $lastBackupAt) < $this->backupIntervalSeconds) {
            return;
        }

        $this->navidrome->walCheckpointTruncate();
        $path = $this->backup->backup();
        if ($path !== null) {
            $report->backupPath = $path;
            $report->intermediateBackups++;
        }
        $lastBackupAt = time();
    }

    /**
     * @param list<array{sync: ScrobbleSync, matchedId: ?string, status: string, strategy: ?string, willInsert: bool}> $batch
     * @return array{0: string, 1: ?string, 2: ?string, 3: bool}
     */
    private function classify(
        MatchResult $result,
        string $userId,
        \DateTimeImmutable $playedAt,
        int $toleranceSeconds,
        array $batch,
        bool $dryRun,
        NavidromeSyncReport $report,
    ): array {
        if ($result->status === MatchResult::STATUS_SKIPPED) {
            $report->skipped++;
            return [ScrobbleSync::STATUS_SKIPPED, null, null, false];
        }

        if ($result->mediaFileId === null) {
            $report->unmatched++;
            return [ScrobbleSync::STATUS_UNMATCHED, null, null, false];
        }

        $mediaFileId = $result->mediaFileId;

        $isDuplicate = $this->navidrome->scrobbleExistsNear($userId, $mediaFileId, $playedAt, $toleranceSeconds)
            || $this->batchHasNearScrobble($batch, $mediaFileId, $playedAt, $toleranceSeconds);

        if ($isDuplicate) {
            $report->duplicates++;
            return [ScrobbleSync::STATUS_DUPLICATE, $mediaFileId, $result->strategy, false];
        }

        $report->matched++;
        $willInsert = !$dryRun;
        return [ScrobbleSync::STATUS_MATCHED, $mediaFileId, $result->strategy, $willInsert];
    }

    /**
     * @param list<array{sync: ScrobbleSync, matchedId: ?string, status: string, strategy: ?string, willInsert: bool}> $batch
     */
    private function batchHasNearScrobble(array $batch, string $mediaFileId, \DateTimeImmutable $playedAt, int $toleranceSeconds): bool
    {
        $ts = $playedAt->getTimestamp();
        foreach ($batch as $row) {
            if (!$row['willInsert'] || $row['matchedId'] !== $mediaFileId) {
                continue;
            }
            if (abs($row['sync']->getScrobble()->getPlayedAt()->getTimestamp() - $ts) <= $toleranceSeconds) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<array{sync: ScrobbleSync, matchedId: ?string, status: string, strategy: ?string, willInsert: bool}> $batch
     */
    private function flushBatch(array $batch, string $userId, ?RunHistory $run): void
    {
        if ($batch === []) {
            return;
        }

        // 1. Write to Navidrome atomically.
        $this->navidrome->beginWriteTransaction();
        try {
            $touched = [];
            foreach ($batch as $row) {
                if ($row['willInsert'] && $row['matchedId'] !== null) {
                    $this->navidrome->insertScrobble($userId, $row['matchedId'], $row['sync']->getScrobble()->getPlayedAt());
                    $touched[$row['matchedId']] = true;
                }
            }
            // Sync the displayed play_count / play_date in `annotation` from
            // the freshly populated `scrobbles` rows. Without this, Navidrome's
            // UI keeps showing 0 plays for the imported tracks — `insertScrobble`
            // only touches the `scrobbles` table, while the player reads from
            // `annotation`.
            if ($touched !== []) {
                $this->navidrome->reconcileAnnotationForMediaFiles($userId, array_keys($touched));
            }
            $this->navidrome->commitWrite();
        } catch (\Throwable $e) {
            try {
                $this->navidrome->rollbackWrite();
            } catch (\Throwable) {
            }
            throw $e;
        }

        // 2. Update scrobble_sync rows and flush app-DB.
        foreach ($batch as $row) {
            $sync = $row['sync'];
            match ($row['status']) {
                ScrobbleSync::STATUS_MATCHED => $sync->markMatched((string) $row['matchedId'], (string) $row['strategy'], $run),
                ScrobbleSync::STATUS_DUPLICATE => $sync->markDuplicate((string) $row['matchedId'], (string) $row['strategy'], $run),
                ScrobbleSync::STATUS_UNMATCHED => $sync->markUnmatched($run),
                ScrobbleSync::STATUS_SKIPPED => $sync->markSkipped($run),
                default => null,
            };
            $this->em->persist($sync);
        }
        $this->em->flush();

        // Detach AFTER flush — detaching before persist() throws
        // ORMInvalidArgumentException ("detached entity cannot be persisted")
        // in Doctrine ORM 3, which closes the EM. Doing it here bounds the
        // identity map to one batch worth of entities at most.
        //
        // We also detach the joined Scrobble, otherwise `toIterable()` keeps
        // loading them into the identity map forever (one per yielded row)
        // and a `--limit=50000` run OOMs at ~30k rows on a 128M heap.
        foreach ($batch as $row) {
            $sync = $row['sync'];
            $scrobble = $sync->getScrobble();
            if ($this->em->contains($sync)) {
                $this->em->detach($sync);
            }
            if ($this->em->contains($scrobble)) {
                $this->em->detach($scrobble);
            }
        }
    }
}
