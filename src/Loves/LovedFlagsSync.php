<?php

namespace App\Loves;

use App\LastFm\LovedSyncReport;
use App\Repository\ScrobbleRepository;

/**
 * Flags `scrobbles.loved=1` for every (artist, title) — or matching MBID —
 * the provider reports as loved. Live listen feeds only carry the flag when
 * the track was loved BEFORE the listen landed; this closes that gap.
 *
 * One read of `scrobbles` for the whole run, then chunked updates (#318) —
 * it used to scan the table two to four times per loved track. Matching rule
 * unchanged: the MBID wins when it still has unflagged scrobbles, otherwise
 * the case-insensitive (artist, title) pair.
 */
class LovedFlagsSync
{
    public function __construct(
        private readonly ScrobbleRepository $scrobbles,
    ) {
    }

    public function sync(LovesProviderInterface $provider, bool $dryRun = false): LovedSyncReport
    {
        $report = new LovedSyncReport();

        $loved = [];
        $mbids = [];
        $keys = [];
        foreach ($provider->iterateLoved() as $track) {
            $report->fetched++;
            if ($track->artist === '' && $track->mbid === null) {
                $report->unmatched++;
                continue;
            }
            $key = ScrobbleRepository::lovedKey($track->artist, $track->title);
            $loved[] = ['key' => $key, 'mbid' => $track->mbid !== '' ? $track->mbid : null];
            $keys[$key] = true;
            if ($track->mbid !== null && $track->mbid !== '') {
                $mbids[$track->mbid] = true;
            }
        }
        if ($loved === []) {
            return $report;
        }

        /** @var array<string, list<array{id: int, loved: bool}>> $byMbid */
        $byMbid = [];
        /** @var array<string, list<array{id: int, loved: bool}>> $byKey */
        $byKey = [];
        foreach ($this->scrobbles->findLovedCandidates($provider->user(), $mbids, $keys) as $row) {
            $entry = ['id' => $row['id'], 'loved' => $row['loved']];
            if ($row['mbid'] !== null && isset($mbids[$row['mbid']])) {
                $byMbid[$row['mbid']][] = $entry;
            }
            if (isset($keys[$row['key']])) {
                $byKey[$row['key']][] = $entry;
            }
        }

        $toFlag = [];
        foreach ($loved as $track) {
            $mbidRows = $track['mbid'] !== null ? ($byMbid[$track['mbid']] ?? []) : [];
            $keyRows = $byKey[$track['key']] ?? [];
            if ($mbidRows === [] && $keyRows === []) {
                $report->unmatched++;
                continue;
            }
            $report->matched++;

            $unflagged = array_filter($mbidRows, static fn (array $r): bool => !$r['loved']);
            if ($unflagged === []) {
                $unflagged = array_filter($keyRows, static fn (array $r): bool => !$r['loved']);
            }
            foreach ($unflagged as $r) {
                $toFlag[$r['id']] = true;
            }
        }

        $ids = array_keys($toFlag);
        $report->updatedRows = $dryRun ? count($ids) : $this->scrobbles->flagLovedIds($ids);

        return $report;
    }
}
