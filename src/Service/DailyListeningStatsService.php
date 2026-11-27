<?php

namespace App\Service;

use App\Navidrome\NavidromeRepository;
use App\Repository\ScrobbleRepository;

/**
 * Builds per-day listening series for the daily-stats API (issue #250) from
 * EITHER the Last.fm history (tools DB) OR Navidrome (library DB), each named
 * explicitly. Days are cut in the configured local timezone and the range is
 * ALWAYS zero-filled — a day with no listening is returned with zeros, never
 * omitted, so the caller can tell "nothing played" from "not synced yet".
 *
 * Cross-source notes baked into the contract:
 *  - `duration_seconds` only exists Navidrome-side (`media_file.duration`).
 *    For Last.fm it's resolved per play via `scrobble_sync` → the two DBs
 *    can't be JOINed, so ids are resolved in PHP; `duration_coverage_pct`
 *    says how much of the day's plays carried a duration (100 for Navidrome).
 *  - `loved_added` is ALWAYS taken from Navidrome `annotation.starred_at`
 *    (the only dated "loved" signal) regardless of source.
 */
class DailyListeningStatsService
{
    public const SOURCE_NAVIDROME = 'navidrome';
    /** The listening history (`scrobbles`), whatever the source it was fetched from (#310). */
    public const SOURCE_HISTORY = 'history';
    /** Deprecated alias of SOURCE_HISTORY, accepted until the next major version (#310). */
    public const SOURCE_LASTFM = 'lastfm';
    public const SOURCES = [self::SOURCE_NAVIDROME, self::SOURCE_HISTORY, self::SOURCE_LASTFM];

    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly ScrobbleRepository $scrobbles,
        private readonly LastFmStatsService $lastfmStats,
        private readonly string $timezone,
        private readonly string $historyUser = '',
    ) {
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    /**
     * @param \DateTimeImmutable $fromDay local midnight of the first day (inclusive)
     * @param \DateTimeImmutable $toDay   local midnight of the last day (inclusive)
     *
     * @return list<array{
     *     day: string, tracks: int, distinct_tracks: int, artists: int, albums: int,
     *     duration_seconds: int, duration_coverage_pct: int, loved_added: int,
     *     top_artist: array{name: string, plays: int}|null
     * }>
     */
    public function daily(string $source, \DateTimeImmutable $fromDay, \DateTimeImmutable $toDay): array
    {
        $toExclusive = $toDay->modify('+1 day');

        // loved_added is Navidrome-only (dated starred_at), whatever the source.
        $loved = $this->navidrome->getStarredAddedByDay($fromDay, $toExclusive);

        if ($source === self::SOURCE_HISTORY || $source === self::SOURCE_LASTFM) {
            $user = $this->lastfmStats->resolveUser($this->historyUser);
            $agg = $this->scrobbles->getDailyListening($user, $fromDay, $toExclusive);
            $topByDay = NavidromeRepository::reduceTopArtistPerDay(
                $this->scrobbles->getTopArtistByDay($user, $fromDay, $toExclusive),
            );
            [$durationByDay, $coverageByDay] = $this->lastfmDurationCoverage($user, $fromDay, $toExclusive);
            $navidromeSource = false;
        } else {
            $agg = $this->navidrome->getDailyListening($fromDay, $toExclusive);
            $topByDay = $this->navidrome->getTopArtistByDay($fromDay, $toExclusive);
            $durationByDay = [];
            $coverageByDay = [];
            $navidromeSource = true;
        }

        $days = [];
        for ($d = $fromDay; $d < $toExclusive; $d = $d->modify('+1 day')) {
            $key = $d->format('Y-m-d');
            $a = $agg[$key] ?? [];
            $plays = (int) ($a['tracks'] ?? 0);

            if ($navidromeSource) {
                $duration = (int) ($a['duration_seconds'] ?? 0);
                $coverage = 100;
            } else {
                $duration = $durationByDay[$key] ?? 0;
                $coverage = $coverageByDay[$key] ?? 100;
            }

            $days[] = [
                'day' => $key,
                'tracks' => $plays,
                'distinct_tracks' => (int) ($a['distinct_tracks'] ?? 0),
                'artists' => (int) ($a['artists'] ?? 0),
                'albums' => (int) ($a['albums'] ?? 0),
                'duration_seconds' => $duration,
                'duration_coverage_pct' => $coverage,
                'loved_added' => $loved[$key] ?? 0,
                'top_artist' => $topByDay[$key] ?? null,
            ];
        }

        return $days;
    }

    /**
     * Resolve Last.fm plays → Navidrome durations in PHP (the two DBs can't be
     * JOINed) and derive per-day duration + coverage %.
     *
     * @return array{0: array<string, int>, 1: array<string, int>} [durationByDay, coverageByDay]
     */
    private function lastfmDurationCoverage(?string $user, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->scrobbles->getDailyMatchedTargets($user, $from, $to);

        $ids = [];
        foreach ($rows as $r) {
            if ($r['target_id'] !== null && $r['target_id'] !== '') {
                $ids[$r['target_id']] = true;
            }
        }
        $durationById = [];
        if ($ids !== []) {
            foreach ($this->navidrome->getMediaFileMetadata(array_keys($ids)) as $meta) {
                $durationById[(string) $meta['id']] = (int) $meta['duration'];
            }
        }

        $total = [];
        $matched = [];
        $duration = [];
        foreach ($rows as $r) {
            $day = $r['day'];
            $plays = (int) $r['plays'];
            $total[$day] = ($total[$day] ?? 0) + $plays;
            $tid = $r['target_id'];
            if ($tid !== null && $tid !== '') {
                $matched[$day] = ($matched[$day] ?? 0) + $plays;
                $duration[$day] = ($duration[$day] ?? 0) + ($durationById[$tid] ?? 0) * $plays;
            }
        }

        $coverage = [];
        foreach ($total as $day => $tot) {
            $coverage[$day] = $tot > 0
                ? (int) min(100, round(($matched[$day] ?? 0) / $tot * 100))
                : 100;
        }

        return [$duration, $coverage];
    }
}
