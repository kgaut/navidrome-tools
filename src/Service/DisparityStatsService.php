<?php

namespace App\Service;

use App\Navidrome\NavidromeRepository;

/**
 * Joins the Last.fm per-month series with the Navidrome per-month series
 * and surfaces the months / years with the biggest "missing plays" gap —
 * i.e. periods where I scrobbled a lot on Last.fm but my Navidrome library
 * covers only a fraction of them. Useful to spot which months to backfill
 * (or accept as forever-lost because I was on Spotify back then) before a
 * full wipe + re-import.
 *
 * Bounded by the first Navidrome scrobble month so years pre-dating the
 * library aren't flagged as "100 % gap" — the user couldn't have covered
 * them anyway.
 *
 * Coverage % is clamped to 100: Navidrome can briefly exceed Last.fm right
 * after a sync (we already wrote the scrobble, the matching Last.fm row is
 * still in flight) and that shouldn't look like a "gain". We also clamp
 * the raw gap to 0 in the same case.
 */
class DisparityStatsService
{
    private const TOP_MONTHS = 12;
    private const TOP_YEARS = 10;

    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly LastFmStatsService $lastfm,
        private readonly string $defaultUser = '',
    ) {
    }

    /**
     * @return array{
     *     anchor_month: ?string,
     *     by_month: list<array{month: string, lastfm: int, navidrome: int, gap: int, coverage_pct: int}>,
     *     by_year: list<array{year: string, lastfm: int, navidrome: int, gap: int, coverage_pct: int}>
     * }
     */
    public function compute(?string $user = null): array
    {
        $anchor = $this->navidrome->getFirstScrobbleMonth();
        if ($anchor === null) {
            return ['anchor_month' => null, 'by_month' => [], 'by_year' => []];
        }

        $resolvedUser = $user !== null && $user !== '' ? $user : ($this->defaultUser !== '' ? $this->defaultUser : null);

        $lastfmRows = $this->lastfm->playsByMonthSince($anchor, $resolvedUser);
        $naviRows = $this->navidrome->getPlaysByMonthSince($anchor);

        $months = $this->mergeByMonth($lastfmRows, $naviRows);

        $byMonth = array_values(array_filter($months, static fn (array $m): bool => $m['gap'] >= 1));
        usort($byMonth, static function (array $a, array $b): int {
            return $b['gap'] <=> $a['gap'] ?: strcmp($b['month'], $a['month']);
        });
        $byMonth = array_slice($byMonth, 0, self::TOP_MONTHS);

        return [
            'anchor_month' => $anchor,
            'by_month' => $byMonth,
            'by_year' => $this->aggregateByYear($months),
        ];
    }

    /**
     * @param list<array{month: string, plays: int}> $lastfmRows
     * @param list<array{month: string, plays: int}> $naviRows
     *
     * @return list<array{month: string, lastfm: int, navidrome: int, gap: int, coverage_pct: int}>
     */
    private function mergeByMonth(array $lastfmRows, array $naviRows): array
    {
        $byMonth = [];
        foreach ($lastfmRows as $r) {
            $byMonth[$r['month']] = ['lastfm' => $r['plays'], 'navidrome' => 0];
        }
        foreach ($naviRows as $r) {
            $byMonth[$r['month']] ??= ['lastfm' => 0, 'navidrome' => 0];
            $byMonth[$r['month']]['navidrome'] = $r['plays'];
        }

        $out = [];
        ksort($byMonth);
        foreach ($byMonth as $month => $row) {
            $out[] = self::makeRow((string) $month, $row['lastfm'], $row['navidrome']);
        }

        return $out;
    }

    /**
     * @param list<array{month: string, lastfm: int, navidrome: int, gap: int, coverage_pct: int}> $months
     *
     * @return list<array{year: string, lastfm: int, navidrome: int, gap: int, coverage_pct: int}>
     */
    private function aggregateByYear(array $months): array
    {
        $byYear = [];
        foreach ($months as $m) {
            $year = substr($m['month'], 0, 4);
            $byYear[$year] ??= ['lastfm' => 0, 'navidrome' => 0];
            $byYear[$year]['lastfm'] += $m['lastfm'];
            $byYear[$year]['navidrome'] += $m['navidrome'];
        }

        $out = [];
        foreach ($byYear as $year => $row) {
            $r = self::makeRow((string) $year, $row['lastfm'], $row['navidrome']);
            // Map "month" key to "year" for caller clarity.
            $out[] = [
                'year' => $r['month'],
                'lastfm' => $r['lastfm'],
                'navidrome' => $r['navidrome'],
                'gap' => $r['gap'],
                'coverage_pct' => $r['coverage_pct'],
            ];
        }
        $out = array_values(array_filter($out, static fn (array $y): bool => $y['gap'] >= 1));
        usort($out, static fn (array $a, array $b): int => $b['gap'] <=> $a['gap'] ?: strcmp($b['year'], $a['year']));

        return array_slice($out, 0, self::TOP_YEARS);
    }

    /**
     * @return array{month: string, lastfm: int, navidrome: int, gap: int, coverage_pct: int}
     */
    private static function makeRow(string $month, int $lastfm, int $navidrome): array
    {
        $gap = max(0, $lastfm - $navidrome);
        $coverage = $lastfm > 0 ? min(100, (int) round($navidrome / $lastfm * 100)) : 100;

        return [
            'month' => $month,
            'lastfm' => $lastfm,
            'navidrome' => $navidrome,
            'gap' => $gap,
            'coverage_pct' => $coverage,
        ];
    }
}
