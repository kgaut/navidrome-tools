<?php

namespace App\Strawberry;

use App\Entity\RunHistory;
use App\Entity\ScrobbleSync;
use App\Repository\ScrobbleSyncRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Syncs pending scrobble_sync rows (target=strawberry) into the Strawberry
 * music player database by incrementing playcount and updating lastplayed.
 *
 * Unlike Navidrome, Strawberry stores aggregate counts — no individual
 * scrobble rows. Multiple scrobbles for the same song are grouped into a
 * single UPDATE playcount += N per batch.
 *
 * Navidrome does NOT need to be stopped. Strawberry uses SQLite WAL mode
 * and we only write playcount integers.
 */
class StrawberrySyncService
{
    private const BATCH_SIZE = 100;

    private LoggerInterface $logger;

    public function __construct(
        private readonly ScrobbleSyncRepository $syncRepo,
        private readonly StrawberryRepository $strawberry,
        private readonly EntityManagerInterface $em,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param callable(int $considered, int $matched, int $unmatched): void|null $progress
     */
    public function process(
        int $limit = 0,
        bool $dryRun = false,
        bool $retryUnmatched = false,
        ?RunHistory $run = null,
        ?callable $progress = null,
    ): StrawberrySyncReport {
        if (!$this->strawberry->isAvailable()) {
            $this->logger->warning('Strawberry DB not configured — skipping.');
            return new StrawberrySyncReport();
        }

        $report = new StrawberrySyncReport();
        $report->dryRun = $dryRun;
        $report->retryUnmatched = $retryUnmatched;

        // Prepare pending rows.
        $report->prepared = $this->syncRepo->prepareForTarget(ScrobbleSync::TARGET_STRAWBERRY);

        if ($retryUnmatched) {
            $this->syncRepo->resetUnmatchedToPending(ScrobbleSync::TARGET_STRAWBERRY);
        }

        /** @var list<array{sync: ScrobbleSync, rowid: ?int, playedAt: \DateTimeImmutable}> $batch */
        $batch = [];

        try {
            foreach ($this->syncRepo->streamPending(ScrobbleSync::TARGET_STRAWBERRY, $limit) as $sync) {
                $report->considered++;
                $scrobble = $sync->getScrobble();

                $rowid = $this->findStrawberryRowid($scrobble->getMbidTrack(), $scrobble->getArtist(), $scrobble->getTitle());

                if ($rowid !== null) {
                    $report->matched++;
                } else {
                    $report->unmatched++;
                    $this->logger->debug('Strawberry: no match for {a} — {t}', [
                        'a' => $scrobble->getArtist(),
                        't' => $scrobble->getTitle(),
                    ]);
                }

                if (!$dryRun) {
                    $batch[] = [
                        'sync' => $sync,
                        'rowid' => $rowid,
                        'playedAt' => $scrobble->getPlayedAt(),
                    ];
                }

                if (!$dryRun && count($batch) >= self::BATCH_SIZE) {
                    $this->flushBatch($batch, $run);
                    $batch = [];
                }

                if ($progress !== null && $report->considered % 50 === 0) {
                    $progress($report->considered, $report->matched, $report->unmatched);
                }
            }

            if (!$dryRun) {
                $this->flushBatch($batch, $run);
            }

            if ($progress !== null) {
                $progress($report->considered, $report->matched, $report->unmatched);
            }
        } finally {
            $this->strawberry->close();
        }

        return $report;
    }

    /**
     * @param list<array{sync: ScrobbleSync, rowid: ?int, playedAt: \DateTimeImmutable}> $batch
     */
    private function flushBatch(array $batch, ?RunHistory $run): void
    {
        if ($batch === []) {
            return;
        }

        // Group matched rows by Strawberry rowid for batch increment.
        /** @var array<int, array{count: int, maxTs: int, syncIds: list<int>}> $byRowid */
        $byRowid = [];
        $unmatchedSyncs = [];

        foreach ($batch as $row) {
            if ($row['rowid'] !== null) {
                $rowid = $row['rowid'];
                $ts = $row['playedAt']->getTimestamp();
                if (!isset($byRowid[$rowid])) {
                    $byRowid[$rowid] = ['count' => 0, 'maxTs' => $ts, 'syncIds' => []];
                }
                $byRowid[$rowid]['count']++;
                if ($ts > $byRowid[$rowid]['maxTs']) {
                    $byRowid[$rowid]['maxTs'] = $ts;
                }
                $byRowid[$rowid]['syncIds'][] = spl_object_id($row['sync']);
                $row['sync']->markMatched((string) $rowid, 'strawberry-rowid', $run);
            } else {
                $row['sync']->markUnmatched($run);
                $unmatchedSyncs[] = $row['sync'];
            }
            $this->em->persist($row['sync']);
        }

        // Apply Strawberry playcount updates.
        foreach ($byRowid as $rowid => $agg) {
            $this->strawberry->incrementPlaycount($rowid, $agg['count'], $agg['maxTs']);
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

    private function findStrawberryRowid(?string $mbid, string $artist, string $title): ?int
    {
        // 1. MBID lookup.
        if ($mbid !== null && $mbid !== '') {
            $row = $this->strawberry->findSongByMbid($mbid);
            if ($row !== null) {
                return $row['rowid'];
            }
        }

        // 2. Exact artist+title.
        $rows = $this->strawberry->findSongsByArtistTitle($artist, $title);
        if (count($rows) === 1) {
            return $rows[0]['rowid'];
        }
        if (count($rows) > 1) {
            usort($rows, static fn (array $a, array $b): int => $b['playcount'] <=> $a['playcount'] ?: $a['rowid'] <=> $b['rowid']);
            return $rows[0]['rowid'];
        }

        // 3. Fuzzy fallback (strip feat./version markers).
        $rows = $this->strawberry->findSongsByArtistTitleFuzzy($artist, $title);
        if ($rows !== []) {
            usort($rows, static fn (array $a, array $b): int => $b['playcount'] <=> $a['playcount'] ?: $a['rowid'] <=> $b['rowid']);
            return $rows[0]['rowid'];
        }

        return null;
    }
}
