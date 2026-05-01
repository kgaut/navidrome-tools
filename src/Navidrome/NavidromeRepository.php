<?php

namespace App\Navidrome;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception as DbalException;

class NavidromeRepository
{
    private ?Connection $connection = null;
    private ?bool $hasScrobblesCache = null;
    private ?string $userIdCache = null;

    public function __construct(
        private readonly string $dbPath,
        private readonly string $userName,
    ) {
    }

    public function isAvailable(): bool
    {
        try {
            $this->connection()->executeQuery('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function hasScrobblesTable(): bool
    {
        if ($this->hasScrobblesCache !== null) {
            return $this->hasScrobblesCache;
        }

        $row = $this->connection()->fetchOne(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='scrobbles'"
        );

        return $this->hasScrobblesCache = ($row !== false);
    }

    public function resolveUserId(): string
    {
        if ($this->userIdCache !== null) {
            return $this->userIdCache;
        }

        $id = $this->connection()->fetchOne(
            'SELECT id FROM user WHERE user_name = :name LIMIT 1',
            ['name' => $this->userName],
        );
        if (!is_string($id) || $id === '') {
            throw new \RuntimeException(sprintf(
                'Navidrome user "%s" not found in database. Check NAVIDROME_USER.',
                $this->userName,
            ));
        }

        return $this->userIdCache = $id;
    }

    /**
     * Top tracks within [from, to). Uses scrobbles when available, otherwise
     * falls back to annotation.play_date.
     *
     * @return string[] media_file ids ordered by play count DESC
     */
    public function topTracksInWindow(\DateTimeInterface $from, \DateTimeInterface $to, int $limit): array
    {
        $userId = $this->resolveUserId();
        $fromStr = $from->format('Y-m-d H:i:s');
        $toStr = $to->format('Y-m-d H:i:s');

        if ($this->hasScrobblesTable()) {
            $sql = <<<'SQL'
                SELECT s.media_file_id AS id
                FROM scrobbles s
                WHERE s.user_id = :uid
                  AND s.submission_time >= :from
                  AND s.submission_time <  :to
                GROUP BY s.media_file_id
                ORDER BY COUNT(*) DESC, MAX(s.submission_time) DESC
                LIMIT :lim
            SQL;
        } else {
            $sql = <<<'SQL'
                SELECT a.item_id AS id
                FROM annotation a
                WHERE a.item_type = 'media_file'
                  AND a.user_id = :uid
                  AND a.play_date >= :from
                  AND a.play_date <  :to
                ORDER BY a.play_count DESC, a.play_date DESC
                LIMIT :lim
            SQL;
        }

        $rows = $this->connection()->fetchAllAssociative($sql, [
            'uid' => $userId,
            'from' => $fromStr,
            'to' => $toStr,
            'lim' => $limit,
        ], [
            'lim' => \Doctrine\DBAL\ParameterType::INTEGER,
        ]);

        return array_map(static fn (array $r) => (string) $r['id'], $rows);
    }

    /**
     * All-time top: based on annotation.play_count (always available).
     *
     * @return string[]
     */
    public function topAllTime(int $limit): array
    {
        $userId = $this->resolveUserId();
        $sql = <<<'SQL'
            SELECT a.item_id AS id
            FROM annotation a
            WHERE a.item_type = 'media_file'
              AND a.user_id = :uid
              AND a.play_count > 0
            ORDER BY a.play_count DESC, a.play_date DESC
            LIMIT :lim
        SQL;

        $rows = $this->connection()->fetchAllAssociative($sql, [
            'uid' => $userId,
            'lim' => $limit,
        ], [
            'lim' => \Doctrine\DBAL\ParameterType::INTEGER,
        ]);

        return array_map(static fn (array $r) => (string) $r['id'], $rows);
    }

    /**
     * Random media files never played by the configured user.
     *
     * @return string[]
     */
    public function neverPlayedRandom(int $limit): array
    {
        $userId = $this->resolveUserId();
        $sql = <<<'SQL'
            SELECT mf.id
            FROM media_file mf
            LEFT JOIN annotation a
              ON a.item_id = mf.id
             AND a.item_type = 'media_file'
             AND a.user_id = :uid
            WHERE COALESCE(a.play_count, 0) = 0
            ORDER BY RANDOM()
            LIMIT :lim
        SQL;

        $rows = $this->connection()->fetchAllAssociative($sql, [
            'uid' => $userId,
            'lim' => $limit,
        ], [
            'lim' => \Doctrine\DBAL\ParameterType::INTEGER,
        ]);

        return array_map(static fn (array $r) => (string) $r['id'], $rows);
    }

    /**
     * Total number of plays in [from, to). Pass null/null for all-time.
     */
    public function getTotalPlays(?\DateTimeInterface $from, ?\DateTimeInterface $to): int
    {
        $userId = $this->resolveUserId();

        if ($this->hasScrobblesTable() && $from !== null && $to !== null) {
            $sql = 'SELECT COUNT(*) FROM scrobbles
                    WHERE user_id = :uid
                      AND submission_time >= :f AND submission_time < :t';
            $count = $this->connection()->fetchOne($sql, [
                'uid' => $userId,
                'f' => $from->format('Y-m-d H:i:s'),
                't' => $to->format('Y-m-d H:i:s'),
            ]);

            return (int) $count;
        }

        if ($from !== null && $to !== null) {
            // Fallback: only counts plays whose LAST play falls in the window.
            $sql = "SELECT COALESCE(SUM(play_count), 0) FROM annotation
                    WHERE item_type = 'media_file' AND user_id = :uid
                      AND play_date >= :f AND play_date < :t";

            return (int) $this->connection()->fetchOne($sql, [
                'uid' => $userId,
                'f' => $from->format('Y-m-d H:i:s'),
                't' => $to->format('Y-m-d H:i:s'),
            ]);
        }

        $sql = "SELECT COALESCE(SUM(play_count), 0) FROM annotation
                WHERE item_type = 'media_file' AND user_id = :uid";

        return (int) $this->connection()->fetchOne($sql, ['uid' => $userId]);
    }

    /**
     * Number of distinct media files played at least once in [from, to).
     */
    public function getDistinctTracksPlayed(?\DateTimeInterface $from, ?\DateTimeInterface $to): int
    {
        $userId = $this->resolveUserId();

        if ($this->hasScrobblesTable() && $from !== null && $to !== null) {
            $sql = 'SELECT COUNT(DISTINCT media_file_id) FROM scrobbles
                    WHERE user_id = :uid
                      AND submission_time >= :f AND submission_time < :t';

            return (int) $this->connection()->fetchOne($sql, [
                'uid' => $userId,
                'f' => $from->format('Y-m-d H:i:s'),
                't' => $to->format('Y-m-d H:i:s'),
            ]);
        }

        if ($from !== null && $to !== null) {
            $sql = "SELECT COUNT(*) FROM annotation
                    WHERE item_type = 'media_file' AND user_id = :uid
                      AND play_count > 0
                      AND play_date >= :f AND play_date < :t";

            return (int) $this->connection()->fetchOne($sql, [
                'uid' => $userId,
                'f' => $from->format('Y-m-d H:i:s'),
                't' => $to->format('Y-m-d H:i:s'),
            ]);
        }

        $sql = "SELECT COUNT(*) FROM annotation
                WHERE item_type = 'media_file' AND user_id = :uid AND play_count > 0";

        return (int) $this->connection()->fetchOne($sql, ['uid' => $userId]);
    }

    /**
     * Top artists by aggregated plays in [from, to). Pass null/null for all-time.
     *
     * @return list<array{artist: string, plays: int}>
     */
    public function getTopArtists(?\DateTimeInterface $from, ?\DateTimeInterface $to, int $limit): array
    {
        $userId = $this->resolveUserId();

        if ($this->hasScrobblesTable() && $from !== null && $to !== null) {
            $sql = 'SELECT mf.artist AS artist, COUNT(*) AS plays
                    FROM scrobbles s
                    JOIN media_file mf ON mf.id = s.media_file_id
                    WHERE s.user_id = :uid
                      AND s.submission_time >= :f AND s.submission_time < :t
                      AND mf.artist != ""
                    GROUP BY mf.artist
                    ORDER BY plays DESC, artist ASC
                    LIMIT :lim';
            $params = [
                'uid' => $userId,
                'f' => $from->format('Y-m-d H:i:s'),
                't' => $to->format('Y-m-d H:i:s'),
                'lim' => $limit,
            ];
        } elseif ($from !== null && $to !== null) {
            $sql = 'SELECT mf.artist AS artist, COALESCE(SUM(a.play_count), 0) AS plays
                    FROM annotation a
                    JOIN media_file mf ON mf.id = a.item_id
                    WHERE a.item_type = "media_file" AND a.user_id = :uid
                      AND a.play_date >= :f AND a.play_date < :t
                      AND mf.artist != ""
                    GROUP BY mf.artist
                    ORDER BY plays DESC, artist ASC
                    LIMIT :lim';
            $params = [
                'uid' => $userId,
                'f' => $from->format('Y-m-d H:i:s'),
                't' => $to->format('Y-m-d H:i:s'),
                'lim' => $limit,
            ];
        } else {
            $sql = 'SELECT mf.artist AS artist, COALESCE(SUM(a.play_count), 0) AS plays
                    FROM annotation a
                    JOIN media_file mf ON mf.id = a.item_id
                    WHERE a.item_type = "media_file" AND a.user_id = :uid
                      AND a.play_count > 0
                      AND mf.artist != ""
                    GROUP BY mf.artist
                    ORDER BY plays DESC, artist ASC
                    LIMIT :lim';
            $params = ['uid' => $userId, 'lim' => $limit];
        }

        $rows = $this->connection()->fetchAllAssociative($sql, $params, [
            'lim' => \Doctrine\DBAL\ParameterType::INTEGER,
        ]);

        return array_map(
            static fn (array $r) => ['artist' => (string) $r['artist'], 'plays' => (int) $r['plays']],
            $rows,
        );
    }

    /**
     * Top tracks (with full metadata) by aggregated plays in [from, to).
     * Pass null/null for all-time.
     *
     * @return list<array{id: string, title: string, artist: string, album: string, plays: int}>
     */
    public function getTopTracksWithDetails(?\DateTimeInterface $from, ?\DateTimeInterface $to, int $limit): array
    {
        $userId = $this->resolveUserId();

        if ($this->hasScrobblesTable() && $from !== null && $to !== null) {
            $sql = 'SELECT mf.id AS id, mf.title AS title, mf.artist AS artist, mf.album AS album,
                           COUNT(*) AS plays
                    FROM scrobbles s
                    JOIN media_file mf ON mf.id = s.media_file_id
                    WHERE s.user_id = :uid
                      AND s.submission_time >= :f AND s.submission_time < :t
                    GROUP BY mf.id, mf.title, mf.artist, mf.album
                    ORDER BY plays DESC, title ASC
                    LIMIT :lim';
            $params = [
                'uid' => $userId,
                'f' => $from->format('Y-m-d H:i:s'),
                't' => $to->format('Y-m-d H:i:s'),
                'lim' => $limit,
            ];
        } elseif ($from !== null && $to !== null) {
            $sql = 'SELECT mf.id AS id, mf.title AS title, mf.artist AS artist, mf.album AS album,
                           COALESCE(a.play_count, 0) AS plays
                    FROM annotation a
                    JOIN media_file mf ON mf.id = a.item_id
                    WHERE a.item_type = "media_file" AND a.user_id = :uid
                      AND a.play_date >= :f AND a.play_date < :t
                    ORDER BY plays DESC, title ASC
                    LIMIT :lim';
            $params = [
                'uid' => $userId,
                'f' => $from->format('Y-m-d H:i:s'),
                't' => $to->format('Y-m-d H:i:s'),
                'lim' => $limit,
            ];
        } else {
            $sql = 'SELECT mf.id AS id, mf.title AS title, mf.artist AS artist, mf.album AS album,
                           a.play_count AS plays
                    FROM annotation a
                    JOIN media_file mf ON mf.id = a.item_id
                    WHERE a.item_type = "media_file" AND a.user_id = :uid
                      AND a.play_count > 0
                    ORDER BY plays DESC, title ASC
                    LIMIT :lim';
            $params = ['uid' => $userId, 'lim' => $limit];
        }

        $rows = $this->connection()->fetchAllAssociative($sql, $params, [
            'lim' => \Doctrine\DBAL\ParameterType::INTEGER,
        ]);

        return array_map(static fn (array $r) => [
            'id' => (string) $r['id'],
            'title' => (string) $r['title'],
            'artist' => (string) $r['artist'],
            'album' => (string) $r['album'],
            'plays' => (int) $r['plays'],
        ], $rows);
    }

    /**
     * Resolve a list of media_file ids to TrackSummary[], preserving order.
     *
     * @param string[] $ids
     *
     * @return TrackSummary[]
     */
    public function summarize(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $userId = $this->resolveUserId();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = sprintf(
            'SELECT mf.id, mf.title, mf.artist, mf.album, mf.duration,
                    COALESCE(a.play_count, 0) AS plays
             FROM media_file mf
             LEFT JOIN annotation a
               ON a.item_id = mf.id AND a.item_type=\'media_file\' AND a.user_id = ?
             WHERE mf.id IN (%s)',
            $placeholders,
        );

        $rows = $this->connection()->fetchAllAssociative($sql, array_merge([$userId], $ids));
        $byId = [];
        foreach ($rows as $r) {
            $byId[(string) $r['id']] = new TrackSummary(
                id: (string) $r['id'],
                title: (string) ($r['title'] ?? ''),
                artist: (string) ($r['artist'] ?? ''),
                album: (string) ($r['album'] ?? ''),
                duration: (int) ($r['duration'] ?? 0),
                plays: (int) ($r['plays'] ?? 0),
            );
        }

        $out = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
            }
        }

        return $out;
    }

    /**
     * Find an artist id by name. Prefers the dedicated `artist` table when
     * present (Navidrome >= 0.50), falls back on a DISTINCT lookup over
     * media_file. Case- and whitespace-insensitive. Returns null when no
     * match or when more than one artist matches.
     */
    public function findArtistIdByName(string $name): ?string
    {
        $name = self::normalize($name);
        if ($name === '') {
            return null;
        }

        $tables = $this->connection()->fetchAllAssociative(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='artist'",
        );
        if ($tables !== []) {
            $rows = $this->connection()->fetchAllAssociative(
                'SELECT id FROM artist WHERE LOWER(TRIM(name)) = :n LIMIT 2',
                ['n' => $name],
            );
            if (count($rows) === 1) {
                return (string) $rows[0]['id'];
            }
            if (count($rows) > 1) {
                return null;
            }
            // 0 row in artist table → fall through to media_file lookup.
        }

        $rows = $this->connection()->fetchAllAssociative(
            'SELECT DISTINCT artist_id FROM media_file
             WHERE LOWER(TRIM(artist)) = :n AND artist_id != "" LIMIT 2',
            ['n' => $name],
        );
        if (count($rows) === 1) {
            return (string) $rows[0]['artist_id'];
        }

        return null;
    }

    /**
     * Find a media_file by MusicBrainz id (mbz_track_id, recording id, or fallback).
     * Returns null if no match.
     */
    public function findMediaFileByMbid(string $mbid): ?string
    {
        if ($mbid === '') {
            return null;
        }

        // Navidrome's media_file may have either 'mbz_track_id' or
        // 'mbz_recording_id' depending on version. We probe both.
        $columns = $this->mediaFileColumns();
        $candidates = array_values(array_filter(
            ['mbz_track_id', 'mbz_recording_id'],
            static fn (string $c) => in_array($c, $columns, true),
        ));
        if ($candidates === []) {
            return null;
        }

        $where = implode(' OR ', array_map(static fn (string $c) => "$c = :mbid", $candidates));
        $sql = sprintf('SELECT id FROM media_file WHERE %s LIMIT 1', $where);

        $id = $this->connection()->fetchOne($sql, ['mbid' => $mbid]);

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Find a media_file by normalised (artist, title) pair. Case- and whitespace-
     * insensitive. Returns null if no match or ambiguous (>1 match).
     */
    public function findMediaFileByArtistTitle(string $artist, string $title): ?string
    {
        $artist = self::normalize($artist);
        $title = self::normalize($title);
        if ($artist === '' || $title === '') {
            return null;
        }

        $sql = "SELECT id FROM media_file
                WHERE LOWER(TRIM(artist)) = :a
                  AND LOWER(TRIM(title)) = :t
                LIMIT 2";
        $rows = $this->connection()->fetchAllAssociative($sql, ['a' => $artist, 't' => $title]);

        if (count($rows) !== 1) {
            return null;
        }

        return (string) $rows[0]['id'];
    }

    /**
     * True if a scrobble already exists for (user, media_file) within
     * ±$toleranceSeconds of $time.
     */
    public function scrobbleExistsNear(
        string $userId,
        string $mediaFileId,
        \DateTimeInterface $time,
        int $toleranceSeconds = 60,
    ): bool {
        if (!$this->hasScrobblesTable()) {
            return false;
        }

        $immutable = $time instanceof \DateTimeImmutable
            ? $time
            : \DateTimeImmutable::createFromInterface($time);
        $tolerance = new \DateInterval('PT' . max(0, $toleranceSeconds) . 'S');
        $from = $immutable->sub($tolerance);
        $to = $immutable->add($tolerance);

        $sql = 'SELECT 1 FROM scrobbles
                WHERE user_id = :uid AND media_file_id = :mfid
                  AND submission_time >= :f AND submission_time <= :t
                LIMIT 1';

        $found = $this->connection()->fetchOne($sql, [
            'uid' => $userId,
            'mfid' => $mediaFileId,
            'f' => $from->format('Y-m-d H:i:s'),
            't' => $to->format('Y-m-d H:i:s'),
        ]);

        return $found !== false;
    }

    /**
     * Insert a scrobble row. Caller is responsible for dedup. Throws if the
     * scrobbles table does not exist (Navidrome < 0.55) or if the DB is
     * mounted read-only.
     */
    public function insertScrobble(string $userId, string $mediaFileId, \DateTimeInterface $time): void
    {
        if (!$this->hasScrobblesTable()) {
            throw new \RuntimeException(
                'The Navidrome scrobbles table does not exist. Upgrade Navidrome to >= 0.55 (late 2025).',
            );
        }

        $this->connection()->executeStatement(
            'INSERT INTO scrobbles (user_id, media_file_id, submission_time) VALUES (?, ?, ?)',
            [$userId, $mediaFileId, $time->format('Y-m-d H:i:s')],
        );
    }

    private static function normalize(string $s): string
    {
        return mb_strtolower(trim($s));
    }

    /**
     * @return string[]
     */
    private function mediaFileColumns(): array
    {
        /** @var array<int, array{name: string}> $rows */
        $rows = $this->connection()->fetchAllAssociative('PRAGMA table_info(media_file)');

        return array_map(static fn (array $r) => (string) $r['name'], $rows);
    }

    private function connection(): Connection
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        try {
            // We rely on the Docker volume being mounted read-only (`:ro`)
            // for safety. We do not open the DB in SQLite read-only mode
            // because that prevents seeing concurrent writes from Navidrome
            // on some platforms.
            $this->connection = DriverManager::getConnection([
                'driver' => 'pdo_sqlite',
                'path' => $this->dbPath,
                'driverOptions' => [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                ],
            ]);
        } catch (DbalException $e) {
            throw new \RuntimeException('Cannot open Navidrome database at ' . $this->dbPath . ': ' . $e->getMessage(), 0, $e);
        }

        return $this->connection;
    }
}
