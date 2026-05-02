<?php

namespace App\Service;

use App\Navidrome\NavidromeRepository;

/**
 * Compute aggregate stats for a Subsonic playlist (top artists, year
 * distribution, never-played percentage, total duration). Data is pulled
 * from the Subsonic getPlaylist payload (already loaded by the caller)
 * for play counts and starred status, and enriched with `media_file.year`
 * via NavidromeRepository when the local DB is reachable.
 */
final class PlaylistStatsService
{
    public function __construct(
        private readonly NavidromeRepository $navidrome,
    ) {
    }

    /**
     * @param list<array{
     *     id: string, title: string, artist: string, album: string,
     *     duration: int, playCount: int, year: ?int, starred: ?string, path: string
     * }> $tracks Tracks as returned by SubsonicClient::getPlaylist().
     *
     * @return array{
     *     trackCount: int,
     *     totalDuration: int,
     *     starredCount: int,
     *     neverPlayedCount: int,
     *     neverPlayedRatio: float,
     *     topArtists: list<array{artist: string, count: int}>,
     *     topAlbums: list<array{album: string, artist: string, count: int}>,
     *     yearDistribution: array<int, int>,
     *     missingYearCount: int
     * }
     */
    public function compute(array $tracks): array
    {
        $count = count($tracks);
        $totalDuration = 0;
        $starredCount = 0;
        $neverPlayedCount = 0;
        $artistCounts = [];
        $albumCounts = [];

        // Year may be missing from Subsonic — refetch from media_file in
        // one batched query so we can build a meaningful distribution.
        $missingYearIds = [];
        $yearByTrackId = [];
        foreach ($tracks as $t) {
            if ($t['year'] !== null) {
                $yearByTrackId[$t['id']] = $t['year'];
            } else {
                $missingYearIds[] = $t['id'];
            }
        }
        if ($missingYearIds !== [] && $this->navidrome->isAvailable()) {
            foreach ($this->navidrome->getMediaFileMetadata($missingYearIds) as $meta) {
                if ($meta['year'] !== null) {
                    $yearByTrackId[$meta['id']] = $meta['year'];
                }
            }
        }

        foreach ($tracks as $t) {
            $totalDuration += max(0, $t['duration']);
            if ($t['starred'] !== null && $t['starred'] !== '') {
                $starredCount++;
            }
            if ($t['playCount'] === 0) {
                $neverPlayedCount++;
            }
            $artist = trim($t['artist']);
            if ($artist !== '') {
                $artistCounts[$artist] = ($artistCounts[$artist] ?? 0) + 1;
            }
            $album = trim($t['album']);
            if ($album !== '') {
                $key = $album . '|' . $artist;
                $albumCounts[$key] = $albumCounts[$key] ?? ['album' => $album, 'artist' => $artist, 'count' => 0];
                $albumCounts[$key]['count']++;
            }
        }

        arsort($artistCounts);
        $topArtists = [];
        foreach (array_slice($artistCounts, 0, 10, true) as $name => $n) {
            $topArtists[] = ['artist' => $name, 'count' => $n];
        }

        $albumCounts = array_values($albumCounts);
        usort($albumCounts, static fn (array $a, array $b) => $b['count'] <=> $a['count']);
        $topAlbums = array_slice($albumCounts, 0, 10);

        $yearDistribution = [];
        $missingYearCount = 0;
        foreach ($tracks as $t) {
            $year = $yearByTrackId[$t['id']] ?? null;
            if ($year === null) {
                $missingYearCount++;
                continue;
            }
            $yearDistribution[$year] = ($yearDistribution[$year] ?? 0) + 1;
        }
        ksort($yearDistribution);

        $ratio = $count > 0 ? $neverPlayedCount / $count : 0.0;

        return [
            'trackCount' => $count,
            'totalDuration' => $totalDuration,
            'starredCount' => $starredCount,
            'neverPlayedCount' => $neverPlayedCount,
            'neverPlayedRatio' => $ratio,
            'topArtists' => $topArtists,
            'topAlbums' => $topAlbums,
            'yearDistribution' => $yearDistribution,
            'missingYearCount' => $missingYearCount,
        ];
    }
}
