<?php

namespace App\Strawberry;

use App\Navidrome\NavidromeRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;

/**
 * Read/write access to a Strawberry music player SQLite database.
 *
 * Strawberry does not have individual scrobble rows: playback history is
 * stored as aggregate counters on the `songs` table (`playcount` INTEGER,
 * `lastplayed` INTEGER unix timestamp). Syncing a scrobble means finding the
 * matching row and incrementing `playcount` while updating `lastplayed`.
 *
 * The connection is opened lazily and only when $dbPath is non-empty; all
 * find/update methods return early without opening the connection when the
 * integration is disabled.
 */
class StrawberryRepository
{
    private ?Connection $conn = null;

    public function __construct(private readonly string $dbPath)
    {
    }

    public function isAvailable(): bool
    {
        return $this->dbPath !== '';
    }

    /**
     * Find songs matching the given artist and title.
     * Returns an array of rows: ['rowid' => int, 'playcount' => int, 'lastplayed' => int].
     * Matching is done PHP-side after normalisation so it is Unicode-safe
     * without requiring a custom SQLite UDF.
     *
     * @return list<array{rowid: int, playcount: int, lastplayed: int}>
     */
    public function findSongsByArtistTitle(string $artist, string $title): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $artistN = NavidromeRepository::normalize($artist);
        $titleN = NavidromeRepository::normalize($title);

        // Fetch candidates whose title matches case-insensitively (ASCII only
        // for SQLite lower()). PHP-side normalize() then handles punctuation
        // differences (apostrophes, hyphens, etc.). For fully accented strings
        // (e.g. "Björk") the caller should ensure the query uses the same
        // encoding as the DB; accent-stripping across different encodings is
        // handled by the fuzzy fallback (findSongsByArtistTitleFuzzy).
        $rows = $this->getConnection()->fetchAllAssociative(
            'SELECT rowid, playcount, lastplayed, artist, title FROM songs WHERE lower(title) = lower(:title)',
            ['title' => $title],
        );

        $results = [];
        foreach ($rows as $row) {
            $rowArtistN = NavidromeRepository::normalize((string) $row['artist']);
            $rowTitleN = NavidromeRepository::normalize((string) $row['title']);
            if ($rowArtistN === $artistN && $rowTitleN === $titleN) {
                $results[] = [
                    'rowid' => (int) $row['rowid'],
                    'playcount' => (int) $row['playcount'],
                    'lastplayed' => (int) $row['lastplayed'],
                ];
            }
        }

        return $results;
    }

    /**
     * Find songs matching the given artist (with feat. stripped) and title
     * (with version markers stripped). Falls back to findSongsByArtistTitle
     * when no stripping is needed.
     *
     * @return list<array{rowid: int, playcount: int, lastplayed: int}>
     */
    public function findSongsByArtistTitleFuzzy(string $artist, string $title): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $strippedArtist = self::stripFeaturedArtists($artist);
        $bareTitle = self::stripVersionMarkers(self::stripFeaturingFromTitle($title));

        // Try all four combinations: original + stripped
        $variants = [];
        $seen = [];

        foreach ([$artist, $strippedArtist] as $a) {
            foreach ([$title, $bareTitle] as $t) {
                $key = $a . '|||' . $t;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $found = $this->findSongsByArtistTitle($a, $t);
                foreach ($found as $row) {
                    $variants[$row['rowid']] = $row;
                }
            }
        }

        return array_values($variants);
    }

    /**
     * Find a song by MusicBrainz recording ID or track ID (as stored by
     * Strawberry in musicbrainz_recording_id / musicbrainz_track_id).
     *
     * @return array{rowid: int, playcount: int, lastplayed: int}|null
     */
    public function findSongByMbid(string $mbid): ?array
    {
        if (!$this->isAvailable() || $mbid === '') {
            return null;
        }

        $row = $this->getConnection()->fetchAssociative(
            'SELECT rowid, playcount, lastplayed FROM songs '
            . 'WHERE musicbrainz_recording_id = :mbid OR musicbrainz_track_id = :mbid '
            . 'LIMIT 1',
            ['mbid' => $mbid],
        );

        if ($row === false) {
            return null;
        }

        return [
            'rowid' => (int) $row['rowid'],
            'playcount' => (int) $row['playcount'],
            'lastplayed' => (int) $row['lastplayed'],
        ];
    }

    /**
     * Increment the playcount of a song and update lastplayed if the given
     * timestamp is more recent than the current one.
     *
     * $maxPlayedAt is a Unix timestamp. Strawberry uses -1 as sentinel for
     * "never played" so we treat any negative value as 0 when comparing.
     */
    public function incrementPlaycount(int $rowid, int $additionalPlays, int $maxPlayedAt): void
    {
        if (!$this->isAvailable()) {
            return;
        }

        $this->getConnection()->executeStatement(
            'UPDATE songs SET '
            . 'playcount = playcount + :extra, '
            . 'lastplayed = CASE WHEN lastplayed < :ts THEN :ts ELSE lastplayed END '
            . 'WHERE rowid = :rowid',
            [
                'extra' => $additionalPlays,
                'ts' => $maxPlayedAt,
                'rowid' => $rowid,
            ],
            [
                'extra' => ParameterType::INTEGER,
                'ts' => ParameterType::INTEGER,
                'rowid' => ParameterType::INTEGER,
            ],
        );
    }

    public function close(): void
    {
        if ($this->conn !== null) {
            $this->conn->close();
            $this->conn = null;
        }
    }

    private function getConnection(): Connection
    {
        if ($this->conn === null) {
            $this->conn = DriverManager::getConnection([
                'driver' => 'pdo_sqlite',
                'path' => $this->dbPath,
            ]);
        }

        return $this->conn;
    }

    // -----------------------------------------------------------------------
    // String helpers (mirrors NavidromeRepository private static methods)
    // -----------------------------------------------------------------------

    private static function stripFeaturedArtists(string $artist): string
    {
        $stripped = preg_replace('/\s*\((?:feat\.?|ft\.?|featuring)\s+[^)]*\)\s*/iu', '', $artist) ?? $artist;
        $stripped = preg_replace('/\s+(?:feat\.?|ft\.?|featuring)\s+.*$/iu', '', $stripped) ?? $stripped;

        return trim($stripped);
    }

    private static function stripVersionMarkers(string $title): string
    {
        $markers = '(?:'
            . 'remastered \d{4}|remaster \d{4}|\d{4} remastered|\d{4} remaster'
            . '|radio edit|radio mix|radio version'
            . '|album version|album mix|album edit'
            . '|single version|single edit|single mix'
            . '|extended version|extended mix|extended edit'
            . '|mono version|stereo version'
            . '|remastered|remaster'
            . '|live(?:\s[^)\]]+)?'
            . '|acoustic(?:\s+(?:version|mix))?'
            . '|instrumental(?:\s+version)?'
            . '|demo(?:\s+version)?'
            . '|deluxe(?:\s+(?:edition|version))?'
            . ')';

        $stripped = preg_replace('/\s*\(' . $markers . '\)\s*$/iu', '', $title) ?? $title;
        $stripped = preg_replace('/\s*\[' . $markers . '\]\s*$/iu', '', $stripped) ?? $stripped;
        $stripped = preg_replace('/\s+[\-\x{2013}\x{2014}]\s+' . $markers . '\s*$/iu', '', $stripped) ?? $stripped;

        return trim($stripped);
    }

    private static function stripFeaturingFromTitle(string $title): string
    {
        $pattern = '(?:feat\.?|ft\.?|featuring|with)\s+[^)\]]+';
        $stripped = preg_replace('/\s*\(' . $pattern . '\)\s*$/iu', '', $title) ?? $title;
        $stripped = preg_replace('/\s*\[' . $pattern . '\]\s*$/iu', '', $stripped) ?? $stripped;

        return trim($stripped);
    }
}
