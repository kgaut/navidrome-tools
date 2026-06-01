<?php

namespace App\Service;

use App\Entity\StatsSnapshot;
use App\Navidrome\NavidromeRepository;
use App\Repository\StatsSnapshotRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Computes the Navidrome stats page payload and caches it in
 * `stats_snapshot` (period key `navidrome-stats`). The Navidrome DB can be
 * large (tens of thousands of media_file × hundreds of thousands of
 * scrobbles), so a full page render runs ~10 distinct aggregate queries —
 * ranging from a few hundred ms to several seconds on cold caches. Caching
 * keeps the UI snappy and lets a cron job pre-warm the snapshot off-hours.
 */
class NavidromeStatsService
{
    public const SNAPSHOT_KEY = 'navidrome-stats';

    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly StatsSnapshotRepository $snapshots,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Recompute the full payload and persist a snapshot. DateTime objects
     * are flattened to ISO-8601 strings so Doctrine's JSON column round-trips
     * cleanly — Twig's `|date` filter still formats them on the way out.
     *
     * @return array<string, mixed>
     */
    public function compute(): array
    {
        $hasScrobbles = $this->navidrome->hasScrobblesTable();

        $today = new \DateTimeImmutable('today');
        $periodFrom = $today->modify('-12 months');
        $allDays = $this->navidrome->getListenedDays(null, null);
        $periodDays = $this->navidrome->getListenedDays($periodFrom, null);
        $alltime = StreakStats::compute($allDays, $today);
        $period = StreakStats::compute($periodDays, $today);

        $data = [
            'computed_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'has_scrobbles' => $hasScrobbles,
            'library' => $this->navidrome->getLibraryCounts(),
            'starred' => $this->navidrome->getStarredCounts(),
            'total_plays' => $this->navidrome->getTotalPlays(null, null),
            'distinct_played' => $this->navidrome->getDistinctTracksPlayed(null, null),
            'streaks' => [
                'longest_alltime' => $alltime['longest'],
                'longest_alltime_started_at' => $alltime['longest_started_at'],
                'longest_alltime_ended_at' => $alltime['longest_ended_at'],
                'longest_period' => $period['longest'],
                'longest_period_started_at' => $period['longest_started_at'],
                'longest_period_ended_at' => $period['longest_ended_at'],
                'current' => $alltime['current'],
                'current_started_at' => $alltime['current_started_at'],
                'current_ended_at' => $alltime['current_ended_at'],
                'period_months' => 12,
            ],
            'recent_scrobbles' => self::flattenScrobbles($this->navidrome->getRecentScrobbles(100)),
            'recent_starred' => self::flattenStarred($this->navidrome->getRecentStarredTracks(25)),
            'top_artists' => $this->navidrome->getTopArtists(null, null, 15),
            'top_tracks' => $this->navidrome->getTopTracksWithDetails(null, null, 15),
            'top_albums' => $this->navidrome->getTopAlbums(null, null, 15),
            'plays_by_month' => $this->navidrome->getPlaysByMonth(12),
            'plays_by_week' => $this->navidrome->getPlaysByWeek(15),
            'plays_by_day' => $this->navidrome->getPlaysByDay(15),
        ];

        $snapshot = $this->snapshots->findOneByPeriod(self::SNAPSHOT_KEY);
        if ($snapshot === null) {
            $snapshot = new StatsSnapshot(self::SNAPSHOT_KEY);
            $this->em->persist($snapshot);
        }
        $snapshot->setData($data);
        $this->em->flush();

        return $data;
    }

    /**
     * Return the cached payload, or null when the snapshot has never been
     * computed. The UI surfaces the absence as a « run the refresh button »
     * banner so a slow cold page render doesn't surprise the user.
     *
     * @return array<string, mixed>|null
     */
    public function get(): ?array
    {
        $snapshot = $this->snapshots->findOneByPeriod(self::SNAPSHOT_KEY);
        if ($snapshot === null) {
            return null;
        }

        /** @var array<string, mixed> */
        return $snapshot->getData();
    }

    /**
     * @param list<array{media_file_id: string, played_at: \DateTimeImmutable, artist: string, title: string, album: string}> $rows
     *
     * @return list<array{media_file_id: string, played_at: string, artist: string, title: string, album: string}>
     */
    private static function flattenScrobbles(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'media_file_id' => $r['media_file_id'],
            'played_at' => $r['played_at']->format(\DATE_ATOM),
            'artist' => $r['artist'],
            'title' => $r['title'],
            'album' => $r['album'],
        ], $rows);
    }

    /**
     * @param list<array{id: string, title: string, artist: string, album: string, starred_at: \DateTimeImmutable}> $rows
     *
     * @return list<array{id: string, title: string, artist: string, album: string, starred_at: string}>
     */
    private static function flattenStarred(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'id' => $r['id'],
            'title' => $r['title'],
            'artist' => $r['artist'],
            'album' => $r['album'],
            'starred_at' => $r['starred_at']->format(\DATE_ATOM),
        ], $rows);
    }
}
