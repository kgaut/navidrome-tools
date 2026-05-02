<?php

namespace App\LastFm;

class ImportReport
{
    public int $fetched = 0;
    public int $inserted = 0;
    public int $duplicates = 0;
    public int $unmatched = 0;
    public int $skipped = 0;

    /** @var array<string, array{artist: string, title: string, album: string, count: int}> */
    private array $unmatchedAggregate = [];

    public function recordUnmatched(LastFmScrobble $scrobble): void
    {
        $this->unmatched++;
        $key = mb_strtolower(trim($scrobble->artist)) . "\0" . mb_strtolower(trim($scrobble->title));
        if (!isset($this->unmatchedAggregate[$key])) {
            $this->unmatchedAggregate[$key] = [
                'artist' => $scrobble->artist,
                'title' => $scrobble->title,
                'album' => $scrobble->album,
                'count' => 0,
            ];
        }
        $this->unmatchedAggregate[$key]['count']++;
    }

    /**
     * Unmatched tracks aggregated by (artist, title), ordered by play count
     * descending then by artist/title alphabetically.
     *
     * @return list<array{artist: string, title: string, album: string, count: int}>
     */
    public function unmatchedRanking(?int $limit = null): array
    {
        $rows = array_values($this->unmatchedAggregate);
        usort($rows, static function (array $a, array $b): int {
            return $b['count'] <=> $a['count']
                ?: strcasecmp($a['artist'], $b['artist'])
                ?: strcasecmp($a['title'], $b['title']);
        });

        return $limit === null ? $rows : array_slice($rows, 0, $limit);
    }

    public function distinctUnmatched(): int
    {
        return count($this->unmatchedAggregate);
    }

    /**
     * Unmatched artists, scrobble counts summed across every unmatched
     * (artist, title) pair, ordered by scrobble count DESC then artist ASC.
     *
     * @return list<array{artist: string, scrobbles: int}>
     */
    public function unmatchedArtistsRanking(?int $limit = null): array
    {
        /** @var array<string, array{artist: string, scrobbles: int}> $byArtist */
        $byArtist = [];
        foreach ($this->unmatchedAggregate as $row) {
            $key = mb_strtolower(trim($row['artist']));
            if (!isset($byArtist[$key])) {
                $byArtist[$key] = ['artist' => $row['artist'], 'scrobbles' => 0];
            }
            $byArtist[$key]['scrobbles'] += $row['count'];
        }

        $rows = array_values($byArtist);
        usort($rows, static function (array $a, array $b): int {
            return $b['scrobbles'] <=> $a['scrobbles']
                ?: strcasecmp($a['artist'], $b['artist']);
        });

        return $limit === null ? $rows : array_slice($rows, 0, $limit);
    }
}
