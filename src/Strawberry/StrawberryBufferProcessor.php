<?php

namespace App\Strawberry;

use App\Entity\LastFmBufferedScrobble;
use App\Entity\RunHistory;
use App\Repository\LastFmBufferedScrobbleRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Syncs buffered Last.fm scrobbles to the Strawberry music player database.
 *
 * Unlike Navidrome, Strawberry stores playback as aggregate counters
 * (playcount + lastplayed) on the `songs` table — there are no individual
 * scrobble rows. For each unsynced buffer row we find the matching Strawberry
 * song and increment its playcount. Unmatched rows are left with
 * synced_strawberry = false so they are retried automatically on the next run
 * (e.g. after the user adds the song to their Strawberry library).
 *
 * Navidrome does not need to be stopped — we only write to the Strawberry DB.
 * Strawberry uses SQLite WAL mode, so concurrent reads from a running instance
 * are safe.
 *
 * Batch processing: buffer rows are accumulated in groups of BATCH_SIZE.
 * Within each batch, matched rows are grouped by Strawberry rowid so that
 * N scrobbles for the same song result in a single UPDATE playcount += N.
 */
class StrawberryBufferProcessor
{
    private const int BATCH_SIZE = 100;

    private LoggerInterface $logger;

    public function __construct(
        private readonly LastFmBufferedScrobbleRepository $bufferRepo,
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
        ?RunHistory $auditRun = null,
        ?callable $progress = null,
    ): StrawberryProcessReport {
        if (!$this->strawberry->isAvailable()) {
            $this->logger->warning('Strawberry DB path not configured — skipping Strawberry sync.');

            return new StrawberryProcessReport();
        }

        $report = new StrawberryProcessReport();
        $report->retryUnmatched = $retryUnmatched;
        $appConnection = $this->em->getConnection();

        /** @var list<array{
         *     bufferId: int,
         *     artist: string,
         *     title: string,
         *     mbid: ?string,
         *     playedAt: \DateTimeImmutable,
         *     strawberryRowid: ?int,
         * }> $pending
         */
        $pending = [];

        try {
            foreach ($this->bufferRepo->streamUnsyncedStrawberry($limit, $retryUnmatched) as $buffered) {
                /** @var LastFmBufferedScrobble $buffered */
                $report->considered++;

                $rowid = $this->findStrawberryRowid($buffered);

                if ($rowid !== null) {
                    $report->matched++;
                } else {
                    $report->unmatched++;
                    $this->logger->debug('Strawberry: no match for {artist} — {title}', [
                        'artist' => $buffered->getArtist(),
                        'title' => $buffered->getTitle(),
                    ]);
                }

                if (!$dryRun) {
                    $pending[] = [
                        'bufferId' => $buffered->getId(),
                        'artist' => $buffered->getArtist(),
                        'title' => $buffered->getTitle(),
                        'mbid' => $buffered->getMbid(),
                        'playedAt' => $buffered->getPlayedAt(),
                        'strawberryRowid' => $rowid,
                    ];
                }

                if ($this->em->contains($buffered)) {
                    $this->em->detach($buffered);
                }

                if (!$dryRun && count($pending) >= self::BATCH_SIZE) {
                    $this->flushBatch($pending, $appConnection);
                    $pending = [];
                }

                if ($progress !== null && $report->considered % 50 === 0) {
                    $progress($report->considered, $report->matched, $report->unmatched);
                }
            }

            if (!$dryRun) {
                $this->flushBatch($pending, $appConnection);
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
     * @param list<array{
     *     bufferId: int,
     *     artist: string,
     *     title: string,
     *     mbid: ?string,
     *     playedAt: \DateTimeImmutable,
     *     strawberryRowid: ?int,
     * }> $pending
     */
    private function flushBatch(array $pending, Connection $appConnection): void
    {
        if ($pending === []) {
            return;
        }

        // Group matched rows by Strawberry rowid → [rowid => [count, maxTs]]
        /** @var array<int, array{count: int, maxTs: int}> $byRowid */
        $byRowid = [];
        $matchedBufferIds = [];
        $unmatchedBufferIds = [];

        foreach ($pending as $row) {
            if ($row['strawberryRowid'] !== null) {
                $rowid = $row['strawberryRowid'];
                $ts = $row['playedAt']->getTimestamp();
                if (!isset($byRowid[$rowid])) {
                    $byRowid[$rowid] = ['count' => 0, 'maxTs' => $ts];
                }
                $byRowid[$rowid]['count']++;
                if ($ts > $byRowid[$rowid]['maxTs']) {
                    $byRowid[$rowid]['maxTs'] = $ts;
                }
                $matchedBufferIds[] = $row['bufferId'];
            } else {
                // Unmatched: do NOT mark synced — will be retried next run.
                $unmatchedBufferIds[] = $row['bufferId'];
            }
        }

        // Apply Strawberry playcount updates.
        foreach ($byRowid as $rowid => $agg) {
            $this->strawberry->incrementPlaycount($rowid, $agg['count'], $agg['maxTs']);
        }

        $nowSql = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        // Mark matched rows as synced + set attempted_at.
        if ($matchedBufferIds !== []) {
            $appConnection->executeStatement(
                'UPDATE lastfm_import_buffer SET synced_strawberry = 1, strawberry_attempted_at = :now '
                . 'WHERE id IN (' . implode(',', $matchedBufferIds) . ')',
                ['now' => $nowSql],
            );
        }

        // For unmatched rows: set attempted_at but leave synced_strawberry = 0,
        // so they are visible as "unmatched" (not "pending") on the next run.
        if ($unmatchedBufferIds !== []) {
            $appConnection->executeStatement(
                'UPDATE lastfm_import_buffer SET strawberry_attempted_at = :now '
                . 'WHERE id IN (' . implode(',', $unmatchedBufferIds) . ')',
                ['now' => $nowSql],
            );
            $this->logger->debug(
                'Strawberry: {n} unmatched row(s) — use --retry-unmatched to re-attempt.',
                ['n' => count($unmatchedBufferIds)],
            );
        }
    }

    private function findStrawberryRowid(LastFmBufferedScrobble $buffered): ?int
    {
        // 1. MBID lookup (fastest, most reliable).
        if ($buffered->getMbid() !== null && $buffered->getMbid() !== '') {
            $row = $this->strawberry->findSongByMbid($buffered->getMbid());
            if ($row !== null) {
                return $row['rowid'];
            }
        }

        // 2. Exact (artist, title) match.
        $rows = $this->strawberry->findSongsByArtistTitle($buffered->getArtist(), $buffered->getTitle());
        if (count($rows) === 1) {
            return $rows[0]['rowid'];
        }

        if (count($rows) > 1) {
            // Multiple songs with same artist+title (e.g. live + studio): pick
            // the one with the highest playcount as the "canonical" version, or
            // fallback to the lowest rowid for determinism.
            usort($rows, static fn (array $a, array $b): int => $b['playcount'] <=> $a['playcount'] ?: $a['rowid'] <=> $b['rowid']);

            return $rows[0]['rowid'];
        }

        // 3. Fuzzy fallback: strip feat. from artist and/or version markers from title.
        $rows = $this->strawberry->findSongsByArtistTitleFuzzy($buffered->getArtist(), $buffered->getTitle());
        if ($rows !== []) {
            usort($rows, static fn (array $a, array $b): int => $b['playcount'] <=> $a['playcount'] ?: $a['rowid'] <=> $b['rowid']);

            return $rows[0]['rowid'];
        }

        return null;
    }
}
