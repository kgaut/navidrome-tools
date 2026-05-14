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
    /** @var array<string>|null */
    private ?array $scrobbleColumnsCache = null;

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

    /**
     * Most recent scrobbles for the configured user, joined with media_file
     * to expose artist/title/album. Returns an empty array when the
     * scrobbles table does not exist (Navidrome < 0.55).
     *
     * @return list<array{media_file_id: string, played_at: \DateTimeImmutable, artist: string, title: string, album: string}>
     */
    public function getRecentScrobbles(int $limit): array
    {
        if (!$this->hasScrobblesTable()) {
            return [];
        }

        $userId = $this->resolveUserId();
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT s.media_file_id AS id, s.submission_time AS ts,
                    mf.artist AS artist, mf.title AS title, mf.album AS album
             FROM scrobbles s
             LEFT JOIN media_file mf ON mf.id = s.media_file_id
             WHERE s.user_id = :uid
             ORDER BY s.submission_time DESC
             LIMIT :lim',
            ['uid' => $userId, 'lim' => $limit],
            ['lim' => \Doctrine\DBAL\ParameterType::INTEGER],
        );

        return array_map(static fn (array $r): array => [
            'media_file_id' => (string) $r['id'],
            'played_at' => (new \DateTimeImmutable('@' . (int) $r['ts']))->setTimezone(new \DateTimeZone(date_default_timezone_get())),
            'artist' => (string) ($r['artist'] ?? ''),
            'title' => (string) ($r['title'] ?? ''),
            'album' => (string) ($r['album'] ?? ''),
        ], $rows);
    }

    /**
     * Total number of rows in the Navidrome `scrobbles` table for any user.
     * Returns 0 when the table does not exist (Navidrome < 0.55).
     */
    public function getScrobblesCount(): int
    {
        if (!$this->hasScrobblesTable()) {
            return 0;
        }

        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM scrobbles');
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

    /**
     * Columns of the scrobbles table (lower-cased), or [] when the table
     * is missing. Used to feature-detect optional columns like `client`
     * (Subsonic client name) which exist on Navidrome 0.55+ but not on
     * older or stripped-down installs.
     *
     * @return array<string>
     */
    public function scrobbleColumns(): array
    {
        if ($this->scrobbleColumnsCache !== null) {
            return $this->scrobbleColumnsCache;
        }
        if (!$this->hasScrobblesTable()) {
            return $this->scrobbleColumnsCache = [];
        }
        $rows = $this->connection()->fetchAllAssociative('PRAGMA table_info(scrobbles)');

        return $this->scrobbleColumnsCache = array_map(
            static fn (array $r): string => strtolower((string) $r['name']),
            $rows,
        );
    }

    public function hasScrobbleClient(): bool
    {
        return in_array('client', $this->scrobbleColumns(), true);
    }

    /**
     * Distinct non-empty Subsonic clients found in the scrobbles table.
     * Returns [] when the column doesn't exist.
     *
     * @return list<string>
     */
    public function listScrobbleClients(): array
    {
        if (!$this->hasScrobbleClient()) {
            return [];
        }

        $rows = $this->connection()->fetchAllAssociative(
            'SELECT DISTINCT client FROM scrobbles
             WHERE client IS NOT NULL AND client != \'\'
             ORDER BY client ASC',
        );

        return array_map(static fn (array $r): string => (string) $r['client'], $rows);
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
            $params = ['uid' => $userId, 'from' => $from->getTimestamp(), 'to' => $to->getTimestamp(), 'lim' => $limit];
            $types = [
                'from' => \Doctrine\DBAL\ParameterType::INTEGER,
                'to' => \Doctrine\DBAL\ParameterType::INTEGER,
                'lim' => \Doctrine\DBAL\ParameterType::INTEGER,
            ];
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
            $params = [
                'uid' => $userId,
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
                'lim' => $limit,
            ];
            $types = ['lim' => \Doctrine\DBAL\ParameterType::INTEGER];
        }

        $rows = $this->connection()->fetchAllAssociative($sql, $params, $types);

        return array_map(static fn (array $r) => (string) $r['id'], $rows);
    }

    /**
     * Top tracks aggregated across several disjoint windows. Identical
     * semantics to {@see topTracksInWindow()} but the WHERE clause unions
     * each `[from, to)` pair so a track played in multiple windows ranks
     * higher (used by the anniversary generator that aggregates « same
     * day of year, 1 / 2 / 5 / 10 years ago »).
     *
     * @param list<array{from: \DateTimeInterface, to: \DateTimeInterface}> $windows
     *
     * @return string[] media_file ids ordered by total play count DESC
     */
    public function topTracksInWindows(array $windows, int $limit): array
    {
        if ($windows === []) {
            return [];
        }
        $userId = $this->resolveUserId();

        if ($this->hasScrobblesTable()) {
            $clauses = [];
            $params = ['uid' => $userId, 'lim' => $limit];
            $types = ['lim' => \Doctrine\DBAL\ParameterType::INTEGER];
            foreach ($windows as $i => $w) {
                $fromKey = "f{$i}";
                $toKey = "t{$i}";
                $clauses[] = "(s.submission_time >= :{$fromKey} AND s.submission_time < :{$toKey})";
                $params[$fromKey] = $w['from']->getTimestamp();
                $params[$toKey] = $w['to']->getTimestamp();
                $types[$fromKey] = \Doctrine\DBAL\ParameterType::INTEGER;
                $types[$toKey] = \Doctrine\DBAL\ParameterType::INTEGER;
            }
            $sql = sprintf(
                "SELECT s.media_file_id AS id
                 FROM scrobbles s
                 WHERE s.user_id = :uid AND (%s)
                 GROUP BY s.media_file_id
                 ORDER BY COUNT(*) DESC, MAX(s.submission_time) DESC
                 LIMIT :lim",
                implode(' OR ', $clauses),
            );

            $rows = $this->connection()->fetchAllAssociative($sql, $params, $types);

            return array_map(static fn (array $r): string => (string) $r['id'], $rows);
        }

        // Annotation fallback: only the LAST play matters, so we just match
        // any window the row's play_date falls in. Less reliable but keeps
        // the generator usable on Navidrome < 0.55.
        $clauses = [];
        $params = ['uid' => $userId, 'lim' => $limit];
        $types = ['lim' => \Doctrine\DBAL\ParameterType::INTEGER];
        foreach ($windows as $i => $w) {
            $fromKey = "f{$i}";
            $toKey = "t{$i}";
            $clauses[] = "(a.play_date >= :{$fromKey} AND a.play_date < :{$toKey})";
            $params[$fromKey] = $w['from']->format('Y-m-d H:i:s');
            $params[$toKey] = $w['to']->format('Y-m-d H:i:s');
        }
        $sql = sprintf(
            "SELECT a.item_id AS id
             FROM annotation a
             WHERE a.item_type = 'media_file' AND a.user_id = :uid AND (%s)
             ORDER BY a.play_count DESC, a.play_date DESC
             LIMIT :lim",
            implode(' OR ', $clauses),
        );

        $rows = $this->connection()->fetchAllAssociative($sql, $params, $types);

        return array_map(static fn (array $r): string => (string) $r['id'], $rows);
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
     *
     * Always prefers the `scrobbles` table when present (so a Last.fm
     * import is reflected in the count). Falls back to summing
     * `annotation.play_count` when scrobbles is missing — note that the
     * fallback's "windowed" mode is only an approximation: it counts
     * tracks whose *last* play falls inside the window.
     */
    public function getTotalPlays(?\DateTimeInterface $from, ?\DateTimeInterface $to, ?string $client = null): int
    {
        $userId = $this->resolveUserId();

        if ($this->hasScrobblesTable()) {
            $sql = 'SELECT COUNT(*) FROM scrobbles WHERE user_id = :uid';
            $params = ['uid' => $userId];
            $types = [];
            if ($from !== null && $to !== null) {
                $sql .= ' AND submission_time >= :f AND submission_time < :t';
                $params['f'] = $from->getTimestamp();
                $params['t'] = $to->getTimestamp();
                $types['f'] = \Doctrine\DBAL\ParameterType::INTEGER;
                $types['t'] = \Doctrine\DBAL\ParameterType::INTEGER;
            }
            if ($client !== null && $client !== '' && $this->hasScrobbleClient()) {
                $sql .= ' AND client = :client';
                $params['client'] = $client;
            }

            return (int) $this->connection()->fetchOne($sql, $params, $types);
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
     * Uses scrobbles when available (always accurate), annotation otherwise.
     */
    public function getDistinctTracksPlayed(?\DateTimeInterface $from, ?\DateTimeInterface $to, ?string $client = null): int
    {
        $userId = $this->resolveUserId();

        if ($this->hasScrobblesTable()) {
            $sql = 'SELECT COUNT(DISTINCT media_file_id) FROM scrobbles WHERE user_id = :uid';
            $params = ['uid' => $userId];
            $types = [];
            if ($from !== null && $to !== null) {
                $sql .= ' AND submission_time >= :f AND submission_time < :t';
                $params['f'] = $from->getTimestamp();
                $params['t'] = $to->getTimestamp();
                $types['f'] = \Doctrine\DBAL\ParameterType::INTEGER;
                $types['t'] = \Doctrine\DBAL\ParameterType::INTEGER;
            }
            if ($client !== null && $client !== '' && $this->hasScrobbleClient()) {
                $sql .= ' AND client = :client';
                $params['client'] = $client;
            }

            return (int) $this->connection()->fetchOne($sql, $params, $types);
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
    public function getTopArtists(?\DateTimeInterface $from, ?\DateTimeInterface $to, int $limit, ?string $client = null): array
    {
        $userId = $this->resolveUserId();

        if ($this->hasScrobblesTable()) {
            $sql = 'SELECT mf.artist AS artist, COUNT(*) AS plays
                    FROM scrobbles s
                    JOIN media_file mf ON mf.id = s.media_file_id
                    WHERE s.user_id = :uid
                      AND mf.artist != ""';
            $params = ['uid' => $userId, 'lim' => $limit];
            $types = ['lim' => \Doctrine\DBAL\ParameterType::INTEGER];
            if ($from !== null && $to !== null) {
                $sql .= ' AND s.submission_time >= :f AND s.submission_time < :t';
                $params['f'] = $from->getTimestamp();
                $params['t'] = $to->getTimestamp();
                $types['f'] = \Doctrine\DBAL\ParameterType::INTEGER;
                $types['t'] = \Doctrine\DBAL\ParameterType::INTEGER;
            }
            if ($client !== null && $client !== '' && $this->hasScrobbleClient()) {
                $sql .= ' AND s.client = :client';
                $params['client'] = $client;
            }
            $sql .= ' GROUP BY mf.artist ORDER BY plays DESC, artist ASC LIMIT :lim';

            $rows = $this->connection()->fetchAllAssociative($sql, $params, $types);

            return array_map(
                static fn (array $r) => ['artist' => (string) $r['artist'], 'plays' => (int) $r['plays']],
                $rows,
            );
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
    public function getTopTracksWithDetails(?\DateTimeInterface $from, ?\DateTimeInterface $to, int $limit, ?string $client = null): array
    {
        $userId = $this->resolveUserId();

        if ($this->hasScrobblesTable()) {
            $sql = 'SELECT mf.id AS id, mf.title AS title, mf.artist AS artist, mf.album AS album,
                           COUNT(*) AS plays
                    FROM scrobbles s
                    JOIN media_file mf ON mf.id = s.media_file_id
                    WHERE s.user_id = :uid';
            $params = ['uid' => $userId, 'lim' => $limit];
            $types = ['lim' => \Doctrine\DBAL\ParameterType::INTEGER];
            if ($from !== null && $to !== null) {
                $sql .= ' AND s.submission_time >= :f AND s.submission_time < :t';
                $params['f'] = $from->getTimestamp();
                $params['t'] = $to->getTimestamp();
                $types['f'] = \Doctrine\DBAL\ParameterType::INTEGER;
                $types['t'] = \Doctrine\DBAL\ParameterType::INTEGER;
            }
            if ($client !== null && $client !== '' && $this->hasScrobbleClient()) {
                $sql .= ' AND s.client = :client';
                $params['client'] = $client;
            }
            $sql .= ' GROUP BY mf.id, mf.title, mf.artist, mf.album
                      ORDER BY plays DESC, title ASC LIMIT :lim';

            $rows = $this->connection()->fetchAllAssociative($sql, $params, $types);

            return array_map(static fn (array $r) => [
                'id' => (string) $r['id'],
                'title' => (string) $r['title'],
                'artist' => (string) $r['artist'],
                'album' => (string) $r['album'],
                'plays' => (int) $r['plays'],
            ], $rows);
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
     * Top albums by aggregated plays in [from, to). Pass null/null for all-time.
     * `sample_track_id` is the most-played media_file in the album, used for
     * cover art on the UI.
     *
     * @return list<array{album: string, album_artist: string, plays: int, track_count: int, sample_track_id: string}>
     */
    public function getTopAlbums(?\DateTimeInterface $from, ?\DateTimeInterface $to, int $limit, ?string $client = null): array
    {
        if (!$this->hasScrobblesTable()) {
            return [];
        }

        $userId = $this->resolveUserId();

        $sql = 'SELECT mf.album AS album,
                       COALESCE(NULLIF(mf.album_artist, ""), mf.artist) AS album_artist,
                       COUNT(*) AS plays,
                       COUNT(DISTINCT mf.id) AS track_count,
                       (SELECT s2.media_file_id
                        FROM scrobbles s2
                        JOIN media_file mf2 ON mf2.id = s2.media_file_id
                        WHERE s2.user_id = :uid
                          AND mf2.album = mf.album
                          AND COALESCE(NULLIF(mf2.album_artist, ""), mf2.artist) = COALESCE(NULLIF(mf.album_artist, ""), mf.artist)
                        GROUP BY s2.media_file_id
                        ORDER BY COUNT(*) DESC, s2.media_file_id ASC
                        LIMIT 1) AS sample_track_id
                FROM scrobbles s
                JOIN media_file mf ON mf.id = s.media_file_id
                WHERE s.user_id = :uid
                  AND mf.album != ""';
        $params = ['uid' => $userId, 'lim' => $limit];
        $types = ['lim' => \Doctrine\DBAL\ParameterType::INTEGER];
        if ($from !== null && $to !== null) {
            $sql .= ' AND s.submission_time >= :f AND s.submission_time < :t';
            $params['f'] = $from->getTimestamp();
            $params['t'] = $to->getTimestamp();
            $types['f'] = \Doctrine\DBAL\ParameterType::INTEGER;
            $types['t'] = \Doctrine\DBAL\ParameterType::INTEGER;
        }
        if ($client !== null && $client !== '' && $this->hasScrobbleClient()) {
            $sql .= ' AND s.client = :client';
            $params['client'] = $client;
        }
        $sql .= ' GROUP BY mf.album, COALESCE(NULLIF(mf.album_artist, ""), mf.artist)
                  ORDER BY plays DESC, album ASC LIMIT :lim';

        $rows = $this->connection()->fetchAllAssociative($sql, $params, $types);

        return array_map(static fn (array $r) => [
            'album' => (string) $r['album'],
            'album_artist' => (string) $r['album_artist'],
            'plays' => (int) $r['plays'],
            'track_count' => (int) $r['track_count'],
            'sample_track_id' => (string) ($r['sample_track_id'] ?? ''),
        ], $rows);
    }

    /**
     * Plays per month over the last $monthsBack months. Optionally filtered
     * to a single artist. Months without scrobbles are returned with plays=0.
     *
     * @return list<array{month: string, plays: int}>
     */
    public function getPlaysByMonth(int $monthsBack, ?string $artist = null): array
    {
        if (!$this->hasScrobblesTable()) {
            return [];
        }

        $userId = $this->resolveUserId();
        $now = new \DateTimeImmutable();
        $from = $now->modify('first day of this month')->setTime(0, 0)
            ->modify(sprintf('-%d months', max(0, $monthsBack - 1)));

        $sql = "SELECT strftime('%Y-%m', s.submission_time, 'unixepoch') AS month, COUNT(*) AS plays
                FROM scrobbles s
                JOIN media_file mf ON mf.id = s.media_file_id
                WHERE s.user_id = :uid
                  AND s.submission_time >= :from";
        $params = ['uid' => $userId, 'from' => $from->getTimestamp()];
        $types = ['from' => \Doctrine\DBAL\ParameterType::INTEGER];
        if ($artist !== null && $artist !== '') {
            $sql .= ' AND mf.artist = :artist';
            $params['artist'] = $artist;
        }
        $sql .= ' GROUP BY month ORDER BY month ASC';

        $rows = $this->connection()->fetchAllAssociative($sql, $params, $types);
        $byMonth = [];
        foreach ($rows as $r) {
            $byMonth[(string) $r['month']] = (int) $r['plays'];
        }

        // Fill missing months with zero plays so the chart has a continuous axis.
        $out = [];
        $cursor = $from;
        for ($i = 0; $i < $monthsBack; $i++) {
            $key = $cursor->format('Y-m');
            $out[] = ['month' => $key, 'plays' => $byMonth[$key] ?? 0];
            $cursor = $cursor->modify('+1 month');
        }

        return $out;
    }

    /**
     * Set of normalized artist names already present in `media_file`.
     * Returned as `[normalized => true]` for O(1) « do we already own X? »
     * lookups (used by the discover suggestions). Uses the same
     * `np_normalize` UDF as `findArtistIdByName` so the keys match what
     * external matchers produce client-side.
     *
     * @return array<string, true>
     */
    public function getKnownArtistsNormalized(): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            "SELECT DISTINCT np_normalize(artist) AS norm FROM media_file WHERE artist != ''",
        );

        $out = [];
        foreach ($rows as $r) {
            $key = (string) $r['norm'];
            if ($key !== '') {
                $out[$key] = true;
            }
        }

        return $out;
    }

    /**
     * Albums with no MusicBrainz album id, ranked by lifetime plays.
     * Plays count is taken from `scrobbles` when available (lifetime),
     * falls back to `annotation.play_count` otherwise. Returns [] when
     * the `mbz_album_id` column doesn't exist (very old Navidrome).
     *
     * @return list<array{album: string, album_artist: string, tracks: int, plays: int}>
     */
    public function getIncompleteAlbums(int $limit = 200): array
    {
        $columns = $this->mediaFileColumns();
        if (!in_array('mbz_album_id', $columns, true)) {
            return [];
        }

        $userId = $this->resolveUserId();

        if ($this->hasScrobblesTable()) {
            $sql = "SELECT mf.album AS album,
                           mf.album_artist AS album_artist,
                           COUNT(DISTINCT mf.id) AS tracks,
                           COUNT(s.id) AS plays
                    FROM media_file mf
                    LEFT JOIN scrobbles s ON s.media_file_id = mf.id AND s.user_id = :uid
                    WHERE (mf.mbz_album_id IS NULL OR mf.mbz_album_id = '')
                      AND mf.album != ''
                    GROUP BY mf.album, mf.album_artist
                    ORDER BY plays DESC, tracks DESC, album_artist ASC, album ASC
                    LIMIT :lim";
        } else {
            $sql = "SELECT mf.album AS album,
                           mf.album_artist AS album_artist,
                           COUNT(DISTINCT mf.id) AS tracks,
                           COALESCE(SUM(a.play_count), 0) AS plays
                    FROM media_file mf
                    LEFT JOIN annotation a ON a.item_id = mf.id
                                          AND a.item_type = 'media_file'
                                          AND a.user_id = :uid
                    WHERE (mf.mbz_album_id IS NULL OR mf.mbz_album_id = '')
                      AND mf.album != ''
                    GROUP BY mf.album, mf.album_artist
                    ORDER BY plays DESC, tracks DESC, album_artist ASC, album ASC
                    LIMIT :lim";
        }

        $rows = $this->connection()->fetchAllAssociative(
            $sql,
            ['uid' => $userId, 'lim' => $limit],
            ['lim' => \Doctrine\DBAL\ParameterType::INTEGER],
        );

        return array_map(static fn (array $r): array => [
            'album' => (string) $r['album'],
            'album_artist' => (string) $r['album_artist'],
            'tracks' => (int) $r['tracks'],
            'plays' => (int) $r['plays'],
        ], $rows);
    }

    /**
     * Artists with high lifetime plays that haven't been played in the
     * last $idleMonths months. Sorted by `plays * idle_seconds` desc
     * (so heavy listening + long silence ranks first). Returns at most
     * $limit rows. Empty array when scrobbles is missing — annotation
     * fallback would be unreliable here (no MAX(play_date) per artist).
     *
     * @return list<array{artist: string, plays: int, last_played_at: \DateTimeImmutable, idle_days: int}>
     */
    public function getForgottenArtists(int $minPlays = 50, int $idleMonths = 12, int $limit = 200): array
    {
        if (!$this->hasScrobblesTable()) {
            return [];
        }

        $userId = $this->resolveUserId();
        $now = new \DateTimeImmutable();
        $idleThreshold = $now->modify(sprintf('-%d months', $idleMonths))->getTimestamp();
        $nowTs = $now->getTimestamp();

        $rows = $this->connection()->fetchAllAssociative(
            "SELECT mf.artist AS artist,
                    COUNT(*) AS plays,
                    MAX(s.submission_time) AS last_play
             FROM scrobbles s
             JOIN media_file mf ON mf.id = s.media_file_id
             WHERE s.user_id = :uid AND mf.artist != ''
             GROUP BY mf.artist
             HAVING plays >= :min_plays AND last_play < :idle_threshold
             ORDER BY plays * (:now_ts - last_play) DESC
             LIMIT :lim",
            [
                'uid' => $userId,
                'min_plays' => $minPlays,
                'idle_threshold' => $idleThreshold,
                'now_ts' => $nowTs,
                'lim' => $limit,
            ],
            [
                'min_plays' => \Doctrine\DBAL\ParameterType::INTEGER,
                'idle_threshold' => \Doctrine\DBAL\ParameterType::INTEGER,
                'now_ts' => \Doctrine\DBAL\ParameterType::INTEGER,
                'lim' => \Doctrine\DBAL\ParameterType::INTEGER,
            ],
        );

        $tz = new \DateTimeZone(date_default_timezone_get());

        return array_map(static function (array $r) use ($tz, $nowTs): array {
            $lastPlay = (int) $r['last_play'];

            return [
                'artist' => (string) $r['artist'],
                'plays' => (int) $r['plays'],
                'last_played_at' => (new \DateTimeImmutable('@' . $lastPlay))->setTimezone($tz),
                'idle_days' => (int) floor(($nowTs - $lastPlay) / 86400),
            ];
        }, $rows);
    }

    /**
     * Plays + distinct artists per month over the last $monthsBack months.
     * Months without scrobbles return plays=0, uniques=0.
     *
     * @return list<array{month: string, plays: int, uniques: int}>
     */
    public function getDiversityByMonth(int $monthsBack): array
    {
        if (!$this->hasScrobblesTable()) {
            return [];
        }

        $userId = $this->resolveUserId();
        $now = new \DateTimeImmutable();
        $from = $now->modify('first day of this month')->setTime(0, 0)
            ->modify(sprintf('-%d months', max(0, $monthsBack - 1)));

        $rows = $this->connection()->fetchAllAssociative(
            "SELECT strftime('%Y-%m', s.submission_time, 'unixepoch') AS month,
                    COUNT(*) AS plays,
                    COUNT(DISTINCT mf.artist) AS uniques
             FROM scrobbles s
             JOIN media_file mf ON mf.id = s.media_file_id
             WHERE s.user_id = :uid AND s.submission_time >= :from AND mf.artist != ''
             GROUP BY month
             ORDER BY month ASC",
            ['uid' => $userId, 'from' => $from->getTimestamp()],
            ['from' => \Doctrine\DBAL\ParameterType::INTEGER],
        );
        $byMonth = [];
        foreach ($rows as $r) {
            $byMonth[(string) $r['month']] = [
                'plays' => (int) $r['plays'],
                'uniques' => (int) $r['uniques'],
            ];
        }

        $out = [];
        $cursor = $from;
        for ($i = 0; $i < $monthsBack; $i++) {
            $key = $cursor->format('Y-m');
            $out[] = [
                'month' => $key,
                'plays' => $byMonth[$key]['plays'] ?? 0,
                'uniques' => $byMonth[$key]['uniques'] ?? 0,
            ];
            $cursor = $cursor->modify('+1 month');
        }

        return $out;
    }

    /**
     * Top $topN artists by total plays over the last $monthsBack months,
     * with a per-month timeseries for each.
     *
     * @return list<array{artist: string, artist_id: ?string, total: int, series: list<array{month: string, plays: int}>}>
     */
    public function getTopArtistsTimeline(int $monthsBack, int $topN): array
    {
        if (!$this->hasScrobblesTable()) {
            return [];
        }

        $userId = $this->resolveUserId();
        $now = new \DateTimeImmutable();
        $from = $now->modify('first day of this month')->setTime(0, 0)
            ->modify(sprintf('-%d months', max(0, $monthsBack - 1)));

        // Top artists in window. MAX(artist_id) picks one stable id when
        // the same artist name maps to multiple media_file.artist_id rows
        // (rare). Used by the cover proxy to fetch the artist photo.
        $topRows = $this->connection()->fetchAllAssociative(
            "SELECT mf.artist AS artist, MAX(mf.artist_id) AS artist_id, COUNT(*) AS plays
             FROM scrobbles s
             JOIN media_file mf ON mf.id = s.media_file_id
             WHERE s.user_id = :uid AND s.submission_time >= :from AND mf.artist != ''
             GROUP BY mf.artist
             ORDER BY plays DESC
             LIMIT :lim",
            ['uid' => $userId, 'from' => $from->getTimestamp(), 'lim' => $topN],
            [
                'from' => \Doctrine\DBAL\ParameterType::INTEGER,
                'lim' => \Doctrine\DBAL\ParameterType::INTEGER,
            ],
        );
        if ($topRows === []) {
            return [];
        }
        $topArtists = array_map(static fn (array $r) => (string) $r['artist'], $topRows);

        // Per-(artist, month) plays in a single query
        $placeholders = implode(',', array_fill(0, count($topArtists), '?'));
        $sql = sprintf(
            "SELECT mf.artist AS artist, strftime('%%Y-%%m', s.submission_time, 'unixepoch') AS month, COUNT(*) AS plays
             FROM scrobbles s
             JOIN media_file mf ON mf.id = s.media_file_id
             WHERE s.user_id = ? AND s.submission_time >= ? AND mf.artist IN (%s)
             GROUP BY mf.artist, month",
            $placeholders,
        );
        $byArtistMonth = [];
        foreach (
            $this->connection()->fetchAllAssociative(
                $sql,
                array_merge([$userId, $from->getTimestamp()], $topArtists),
            ) as $r
        ) {
            $byArtistMonth[(string) $r['artist']][(string) $r['month']] = (int) $r['plays'];
        }

        $months = [];
        $cursor = $from;
        for ($i = 0; $i < $monthsBack; $i++) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->modify('+1 month');
        }

        $out = [];
        foreach ($topRows as $top) {
            $artist = (string) $top['artist'];
            $artistId = isset($top['artist_id']) && $top['artist_id'] !== '' ? (string) $top['artist_id'] : null;
            $series = [];
            foreach ($months as $m) {
                $series[] = ['month' => $m, 'plays' => $byArtistMonth[$artist][$m] ?? 0];
            }
            $out[] = ['artist' => $artist, 'artist_id' => $artistId, 'total' => (int) $top['plays'], 'series' => $series];
        }

        return $out;
    }

    /**
     * Heatmap matrix [day-of-week 0..6][hour 0..23] = plays count.
     * Returns a fully-filled 7x24 matrix (zeros included).
     *
     * @return array<int, array<int, int>>
     */
    public function getHeatmapDayHour(?\DateTimeInterface $from, ?\DateTimeInterface $to): array
    {
        $matrix = [];
        for ($d = 0; $d < 7; $d++) {
            $matrix[$d] = array_fill(0, 24, 0);
        }
        if (!$this->hasScrobblesTable()) {
            return $matrix;
        }

        $userId = $this->resolveUserId();
        $sql = "SELECT CAST(strftime('%w', submission_time, 'unixepoch') AS INTEGER) AS dow,
                       CAST(strftime('%H', submission_time, 'unixepoch') AS INTEGER) AS hour,
                       COUNT(*) AS plays
                FROM scrobbles
                WHERE user_id = :uid";
        $params = ['uid' => $userId];
        $types = [];
        if ($from !== null) {
            $sql .= ' AND submission_time >= :from';
            $params['from'] = $from->getTimestamp();
            $types['from'] = \Doctrine\DBAL\ParameterType::INTEGER;
        }
        if ($to !== null) {
            $sql .= ' AND submission_time < :to';
            $params['to'] = $to->getTimestamp();
            $types['to'] = \Doctrine\DBAL\ParameterType::INTEGER;
        }
        $sql .= ' GROUP BY dow, hour';

        foreach ($this->connection()->fetchAllAssociative($sql, $params, $types) as $r) {
            $matrix[(int) $r['dow']][(int) $r['hour']] = (int) $r['plays'];
        }

        return $matrix;
    }

    /**
     * Plays per day for the given calendar year. Days without scrobbles
     * return 0. Keys are 'Y-m-d' strings.
     *
     * @return array<string, int>
     */
    public function getDailyPlays(int $year): array
    {
        $start = new \DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $year));
        $end = new \DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $year + 1));

        $out = [];
        $cursor = $start;
        while ($cursor < $end) {
            $out[$cursor->format('Y-m-d')] = 0;
            $cursor = $cursor->modify('+1 day');
        }

        if (!$this->hasScrobblesTable()) {
            return $out;
        }

        $userId = $this->resolveUserId();
        $rows = $this->connection()->fetchAllAssociative(
            "SELECT date(submission_time, 'unixepoch') AS d, COUNT(*) AS plays
             FROM scrobbles
             WHERE user_id = :uid AND submission_time >= :from AND submission_time < :to
             GROUP BY d",
            [
                'uid' => $userId,
                'from' => $start->getTimestamp(),
                'to' => $end->getTimestamp(),
            ],
            [
                'from' => \Doctrine\DBAL\ParameterType::INTEGER,
                'to' => \Doctrine\DBAL\ParameterType::INTEGER,
            ],
        );
        foreach ($rows as $r) {
            $key = (string) $r['d'];
            if (isset($out[$key])) {
                $out[$key] = (int) $r['plays'];
            }
        }

        return $out;
    }

    /**
     * Artists whose first-ever scrobble for this user falls in $year.
     *
     * @return list<array{artist: string, first_play: string}>
     */
    public function getNewArtists(int $year, int $limit = 100): array
    {
        if (!$this->hasScrobblesTable()) {
            return [];
        }

        $userId = $this->resolveUserId();
        $rows = $this->connection()->fetchAllAssociative(
            "SELECT mf.artist AS artist, datetime(MIN(s.submission_time), 'unixepoch') AS first_play
             FROM scrobbles s
             JOIN media_file mf ON mf.id = s.media_file_id
             WHERE s.user_id = :uid AND mf.artist != ''
             GROUP BY mf.artist
             HAVING strftime('%Y', MIN(s.submission_time), 'unixepoch') = :year
             ORDER BY MIN(s.submission_time) ASC
             LIMIT :lim",
            ['uid' => $userId, 'year' => (string) $year, 'lim' => $limit],
            ['lim' => \Doctrine\DBAL\ParameterType::INTEGER],
        );

        return array_map(
            static fn (array $r) => ['artist' => (string) $r['artist'], 'first_play' => (string) $r['first_play']],
            $rows,
        );
    }

    /**
     * Longest run of consecutive days with at least one scrobble in $year.
     */
    public function getLongestListeningStreak(int $year): int
    {
        $daily = $this->getDailyPlays($year);
        $best = 0;
        $current = 0;
        foreach ($daily as $plays) {
            if ($plays > 0) {
                $current++;
                $best = max($best, $current);
            } else {
                $current = 0;
            }
        }

        return $best;
    }

    /**
     * Most active month (by plays) of $year.
     *
     * @return array{month: string, plays: int}|null
     */
    public function getMostActiveMonth(int $year): ?array
    {
        if (!$this->hasScrobblesTable()) {
            return null;
        }

        $userId = $this->resolveUserId();
        $row = $this->connection()->fetchAssociative(
            "SELECT strftime('%Y-%m', submission_time, 'unixepoch') AS month, COUNT(*) AS plays
             FROM scrobbles
             WHERE user_id = :uid
               AND strftime('%Y', submission_time, 'unixepoch') = :year
             GROUP BY month
             ORDER BY plays DESC, month DESC
             LIMIT 1",
            ['uid' => $userId, 'year' => (string) $year],
        );
        if ($row === false) {
            return null;
        }

        return ['month' => (string) $row['month'], 'plays' => (int) $row['plays']];
    }

    /**
     * Tracks the user once loved (play_count >= $minPlays) but hasn't
     * listened to in a while (last play strictly before $cutoff). Sorted
     * by play_count DESC, then by play_date ASC (oldest forgotten first).
     *
     * @return string[] media_file ids
     */
    public function getSongsLovedAndForgotten(int $minPlays, \DateTimeInterface $cutoff, int $limit): array
    {
        $userId = $this->resolveUserId();
        $sql = "SELECT a.item_id AS id
                FROM annotation a
                JOIN media_file mf ON mf.id = a.item_id
                WHERE a.item_type = 'media_file'
                  AND a.user_id = :uid
                  AND a.play_count >= :min
                  AND a.play_date IS NOT NULL
                  AND a.play_date < :cutoff
                ORDER BY a.play_count DESC, a.play_date ASC
                LIMIT :lim";

        $rows = $this->connection()->fetchAllAssociative($sql, [
            'uid' => $userId,
            'min' => $minPlays,
            'cutoff' => $cutoff->format('Y-m-d H:i:s'),
            'lim' => $limit,
        ], [
            'min' => \Doctrine\DBAL\ParameterType::INTEGER,
            'lim' => \Doctrine\DBAL\ParameterType::INTEGER,
        ]);

        return array_map(static fn (array $r) => (string) $r['id'], $rows);
    }

    /**
     * Resolve a list of media_file ids to TrackSummary[], preserving order.
     *
     * When $from/$to are provided AND the scrobbles table exists, the
     * `plays` field counts the scrobbles for each track inside [from, to)
     * (the same window the playlist generator operates on). Otherwise it
     * falls back to the lifetime annotation.play_count.
     *
     * @param string[] $ids
     *
     * @return TrackSummary[]
     */
    public function summarize(array $ids, ?\DateTimeInterface $from = null, ?\DateTimeInterface $to = null): array
    {
        if ($ids === []) {
            return [];
        }

        $userId = $this->resolveUserId();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $useWindow = $from !== null && $to !== null && $this->hasScrobblesTable();

        if ($useWindow) {
            $sql = sprintf(
                'SELECT mf.id, mf.title, mf.artist, mf.album, mf.duration,
                        COALESCE((SELECT COUNT(*) FROM scrobbles s
                                  WHERE s.media_file_id = mf.id
                                    AND s.user_id = ?
                                    AND s.submission_time >= ?
                                    AND s.submission_time <  ?), 0) AS plays
                 FROM media_file mf
                 WHERE mf.id IN (%s)',
                $placeholders,
            );
            $rows = $this->connection()->fetchAllAssociative(
                $sql,
                array_merge([$userId, $from->getTimestamp(), $to->getTimestamp()], $ids),
                [
                    1 => \Doctrine\DBAL\ParameterType::INTEGER,
                    2 => \Doctrine\DBAL\ParameterType::INTEGER,
                ],
            );
        } else {
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
        }
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
     * Given a list of media_file ids, return those that no longer exist
     * in the Navidrome library (file was removed/moved/renamed). Useful
     * for detecting « dead » entries inside a Subsonic playlist.
     *
     * @param string[] $ids
     *
     * @return string[] subset of $ids, preserving original order
     */
    public function filterMissingMediaFileIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->connection()->fetchAllAssociative(
            sprintf('SELECT id FROM media_file WHERE id IN (%s)', $placeholders),
            $ids,
        );

        $existing = [];
        foreach ($rows as $r) {
            $existing[(string) $r['id']] = true;
        }

        $missing = [];
        foreach ($ids as $id) {
            if (!isset($existing[$id])) {
                $missing[] = $id;
            }
        }

        return $missing;
    }

    /**
     * Per-id metadata needed for playlist stats (year + album + artist).
     * Returned in the same order as input ids; missing rows are silently
     * dropped (use {@see filterMissingMediaFileIds()} to surface them).
     *
     * @param string[] $ids
     *
     * @return array<int, array{id: string, artist: string, album: string, year: ?int, duration: int}>
     */
    public function getMediaFileMetadata(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->connection()->fetchAllAssociative(
            sprintf(
                'SELECT id, artist, album, year, duration FROM media_file WHERE id IN (%s)',
                $placeholders,
            ),
            $ids,
        );

        $byId = [];
        foreach ($rows as $r) {
            $byId[(string) $r['id']] = [
                'id' => (string) $r['id'],
                'artist' => (string) ($r['artist'] ?? ''),
                'album' => (string) ($r['album'] ?? ''),
                'year' => isset($r['year']) ? (int) $r['year'] : null,
                'duration' => (int) ($r['duration'] ?? 0),
            ];
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
                'SELECT id FROM artist WHERE np_normalize(name) = :n LIMIT 2',
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
            "SELECT DISTINCT artist_id FROM media_file
             WHERE np_normalize(artist) = :n AND artist_id != '' LIMIT 2",
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
     * List media_files where every MBID column known to this Navidrome version
     * is either NULL or empty — i.e. tracks that the Last.fm matcher cannot
     * resolve via the most reliable path. Used by the /tagging/missing-mbid
     * audit page and by `app:beets:tag-missing` to feed beets a worklist.
     *
     * Filters and pagination:
     *   - $artistFilter / $albumFilter: case-insensitive substring (LIKE
     *     %term%) on artist / album. Empty / null = no filter.
     *   - $limit: max rows returned. Bound to [1, 1000].
     *   - $offset: SQL offset for pagination.
     *
     * Returned shape exposes the optional `path` column when Navidrome stores
     * it (every modern version does), so callers can hand the absolute file
     * paths to an external tagger like beets.
     *
     * @return list<array{
     *     id: string,
     *     path: string,
     *     artist: string,
     *     album_artist: string,
     *     album: string,
     *     title: string,
     *     year: int
     * }>
     */
    public function findMediaFilesWithoutMbid(
        ?string $artistFilter = null,
        ?string $albumFilter = null,
        int $limit = 100,
        int $offset = 0,
    ): array {
        $where = $this->missingMbidWhereClause();
        if ($where === null) {
            return [];
        }

        $columns = $this->mediaFileColumns();
        $hasPath = in_array('path', $columns, true);
        $select = sprintf(
            'id, %s AS path, artist, album_artist, album, title, year',
            $hasPath ? 'path' : "''",
        );

        $params = [];
        $types = [];
        $clauses = [$where];
        if ($artistFilter !== null && trim($artistFilter) !== '') {
            $clauses[] = 'LOWER(artist) LIKE :artist';
            $params['artist'] = '%' . strtolower(trim($artistFilter)) . '%';
        }
        if ($albumFilter !== null && trim($albumFilter) !== '') {
            $clauses[] = 'LOWER(album) LIKE :album';
            $params['album'] = '%' . strtolower(trim($albumFilter)) . '%';
        }

        $limit = max(1, min(1000, $limit));
        $offset = max(0, $offset);
        $params['lim'] = $limit;
        $params['off'] = $offset;
        $types['lim'] = \Doctrine\DBAL\ParameterType::INTEGER;
        $types['off'] = \Doctrine\DBAL\ParameterType::INTEGER;

        $sql = sprintf(
            'SELECT %s FROM media_file WHERE %s ORDER BY artist, album, title LIMIT :lim OFFSET :off',
            $select,
            implode(' AND ', $clauses),
        );

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection()->fetchAllAssociative($sql, $params, $types);

        return array_map(static fn (array $r): array => [
            'id' => (string) $r['id'],
            'path' => (string) ($r['path'] ?? ''),
            'artist' => (string) ($r['artist'] ?? ''),
            'album_artist' => (string) ($r['album_artist'] ?? ''),
            'album' => (string) ($r['album'] ?? ''),
            'title' => (string) ($r['title'] ?? ''),
            'year' => (int) ($r['year'] ?? 0),
        ], $rows);
    }

    /**
     * Counts every media_file row whose MBID columns are all NULL or empty.
     * Same filter semantics as {@see findMediaFilesWithoutMbid()}; used by
     * the dashboard card and by paginated UIs.
     */
    public function countMediaFilesWithoutMbid(
        ?string $artistFilter = null,
        ?string $albumFilter = null,
    ): int {
        $where = $this->missingMbidWhereClause();
        if ($where === null) {
            return 0;
        }

        $params = [];
        $clauses = [$where];
        if ($artistFilter !== null && trim($artistFilter) !== '') {
            $clauses[] = 'LOWER(artist) LIKE :artist';
            $params['artist'] = '%' . strtolower(trim($artistFilter)) . '%';
        }
        if ($albumFilter !== null && trim($albumFilter) !== '') {
            $clauses[] = 'LOWER(album) LIKE :album';
            $params['album'] = '%' . strtolower(trim($albumFilter)) . '%';
        }

        $sql = sprintf('SELECT COUNT(*) FROM media_file WHERE %s', implode(' AND ', $clauses));

        return (int) $this->connection()->fetchOne($sql, $params);
    }

    /**
     * Build a WHERE fragment matching rows where every MBID column known to
     * this Navidrome version is NULL or ''. Returns null when none of the
     * MBID columns exist (e.g. a stub fixture without MBID columns) — caller
     * should treat that as « nothing to do ».
     */
    private function missingMbidWhereClause(): ?string
    {
        $columns = $this->mediaFileColumns();
        $candidates = array_values(array_filter(
            ['mbz_track_id', 'mbz_recording_id'],
            static fn (string $c) => in_array($c, $columns, true),
        ));
        if ($candidates === []) {
            return null;
        }

        return implode(' AND ', array_map(
            static fn (string $c) => sprintf("(%s IS NULL OR %s = '')", $c, $c),
            $candidates,
        ));
    }

    /**
     * Find a media_file by normalised (artist, title) pair. Case- and whitespace-
     * insensitive. Returns null when there is no match. When several rows share
     * the same (artist, title) — e.g. the same song shipped on a studio album
     * and a compilation — picks one deterministically: prefer the row where
     * `album_artist = artist` (canonical studio release over a tribute /
     * various-artists compilation), tie-broken by `id` ASC for stability across
     * import runs.
     *
     * Lookup strategy when the strict match fails:
     *   1. retry with the lead artist (drop "feat. …" suffix on the artist) ;
     *   2. retry with the bare title (drop "(Radio Edit)" / "- Remastered 2011"
     *      and similar version markers from the title) ;
     *   3. retry with both strips combined.
     * Last.fm tends to credit "Orelsan feat. Thomas Bangalter" or label tracks
     * "Bicycle Race - Remastered 2011" while Navidrome stores the bare form.
     */
    /**
     * Last-resort fuzzy match by Levenshtein distance on (artist, title).
     * Pulls candidate rows whose normalized artist or title shares the same
     * 3-char prefix to avoid scanning the whole library, then picks the row
     * with the smallest combined edit distance under $maxDistance.
     *
     * Off when $maxDistance <= 0 (returns null without querying). Returns
     * null when no candidate is within range.
     */
    public function findMediaFileFuzzy(string $artist, string $title, int $maxDistance): ?string
    {
        if ($maxDistance <= 0) {
            return null;
        }

        $artistN = self::normalize($artist);
        $titleN = self::normalize($title);
        // PHP's levenshtein() is capped at 255 chars; bail out on longer
        // strings rather than crash. (Real-world artist/title rarely exceed
        // 100 chars; this is a safety net.)
        if ($artistN === '' || $titleN === '' || strlen($artistN) > 255 || strlen($titleN) > 255) {
            return null;
        }

        $artistPrefix = mb_substr($artistN, 0, 3);
        $titlePrefix = mb_substr($titleN, 0, 3);
        if ($artistPrefix === '' && $titlePrefix === '') {
            return null;
        }

        $rows = $this->connection()->fetchAllAssociative(
            'SELECT id, artist, title FROM media_file
             WHERE substr(np_normalize(artist), 1, 3) = :ap
                OR substr(np_normalize(title), 1, 3) = :tp',
            ['ap' => $artistPrefix, 'tp' => $titlePrefix],
        );

        $bestId = null;
        $bestScore = PHP_INT_MAX;
        foreach ($rows as $row) {
            $candArtist = self::normalize((string) ($row['artist'] ?? ''));
            $candTitle = self::normalize((string) ($row['title'] ?? ''));
            if (
                $candArtist === '' || $candTitle === ''
                || strlen($candArtist) > 255 || strlen($candTitle) > 255
            ) {
                continue;
            }
            $score = levenshtein($candArtist, $artistN) + levenshtein($candTitle, $titleN);
            if ($score <= $maxDistance && $score < $bestScore) {
                $bestId = (string) $row['id'];
                $bestScore = $score;
            }
        }

        return $bestId;
    }

    /**
     * Disambiguate a (artist, title) lookup with the album. Used as a
     * tighter pre-step to {@see findMediaFileByArtistTitle()} when the
     * scrobble carries a non-empty album: the same song can be present on
     * the studio album, a single, a compilation and a live release —
     * matching the album resolves which row the user actually played
     * instead of falling back to the deterministic but arbitrary tie-break.
     *
     * Strict semantics: returns the id only when *exactly one* row matches
     * the normalized triplet. Returns null on zero match (caller falls back
     * to the couple lookup) or on >1 match (still ambiguous).
     */
    public function findMediaFileByArtistTitleAlbum(string $artist, string $title, string $album): ?string
    {
        $artistN = self::normalize($artist);
        $titleN = self::normalize($title);
        $albumN = self::normalize($album);
        if ($artistN === '' || $titleN === '' || $albumN === '') {
            return null;
        }

        $rows = $this->connection()->fetchAllAssociative(
            'SELECT id FROM media_file
             WHERE np_normalize(artist) = :a
               AND np_normalize(title) = :t
               AND np_normalize(album) = :al
             LIMIT 2',
            ['a' => $artistN, 't' => $titleN, 'al' => $albumN],
        );

        if (count($rows) !== 1) {
            return null;
        }

        return (string) $rows[0]['id'];
    }

    public function findMediaFileByArtistTitle(string $artist, string $title): ?string
    {
        $artistN = self::normalize($artist);
        $titleN = self::normalize($title);
        if ($artistN === '' || $titleN === '') {
            return null;
        }

        $id = $this->lookupExactArtistTitle($artistN, $titleN);
        if ($id !== null) {
            return $id;
        }

        // Strip decorations on the ORIGINAL inputs — the regexes rely on
        // delimiters (parens, dashes, dots in "feat.") that self::normalize()
        // now strips out. Re-normalize the stripped form before lookup.
        $leadArtistN = self::normalize(self::stripFeaturedArtists($artist));
        $bareTitle = self::stripVersionMarkers(self::stripFeaturingFromTitle(
            self::stripTruncatedParen(self::stripTrackNumberPrefix($title)),
        ));
        $bareTitleN = self::normalize($bareTitle);
        $artistChanged = $leadArtistN !== '' && $leadArtistN !== $artistN;
        $titleChanged = $bareTitleN !== '' && $bareTitleN !== $titleN;
        $titleHadFeaturing = self::titleHasFeaturingMarker($title);

        if ($artistChanged) {
            $id = $this->lookupExactArtistTitle($leadArtistN, $titleN);
            if ($id !== null) {
                return $id;
            }
        }
        if ($titleChanged) {
            $id = $this->lookupExactArtistTitle($artistN, $bareTitleN);
            if ($id !== null) {
                return $id;
            }

            // Asymmetric featuring : Last.fm packs "(feat. X)" in the title,
            // Navidrome packs it in the artist column ("Jurassic 5 feat.
            // Roots Manuva / Join the Dots"). Strict match on bareTitle
            // failed because the artist column has a longer string. Try a
            // prefix-based artist lookup, gated on the explicit featuring
            // marker in the original title to keep the false-positive rate
            // tight.
            if ($titleHadFeaturing) {
                $id = $this->lookupArtistPrefixFeaturingTitle($artistN, $bareTitleN);
                if ($id !== null) {
                    return $id;
                }
            }
        }
        if ($artistChanged && $titleChanged) {
            $id = $this->lookupExactArtistTitle($leadArtistN, $bareTitleN);
            if ($id !== null) {
                return $id;
            }
        }

        // Last-resort: split lead artist on multi-artist separators
        // ("&", " - ", " and ", " et ", ","). Conservative: only on the
        // strict (artist, title) couple AND require album_artist to match
        // the stripped artist for confidence.
        $leadOnlyN = self::normalize(self::stripLeadArtist($artist));
        if ($leadOnlyN !== '' && $leadOnlyN !== $artistN && $leadOnlyN !== $leadArtistN) {
            return $this->lookupExactArtistTitleRequiringAlbumArtist($leadOnlyN, $titleN);
        }

        return null;
    }

    private function lookupExactArtistTitle(string $artistNormalized, string $titleNormalized): ?string
    {
        $sql = 'SELECT id FROM media_file
                WHERE np_normalize(artist) = :a
                  AND np_normalize(title) = :t
                ORDER BY (np_normalize(album_artist) = :a) DESC, id ASC
                LIMIT 1';
        $id = $this->connection()->fetchOne($sql, ['a' => $artistNormalized, 't' => $titleNormalized]);

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Refined lookup for the asymmetric-featuring case: title strict on
     * the bare (already feat-stripped) form, artist matched as a prefix
     * followed by a recognised featuring marker. Catches the convention
     * where Last.fm puts "(feat. X)" in the title while Navidrome puts
     * it in the artist column. Gated by {@see titleHasFeaturingMarker()}
     * in the cascade caller to avoid false-positives on plain titles.
     *
     * Marker list mirrors {@see stripFeaturingFromTitle()}: feat / ft /
     * featuring / with — already lower-cased and dot-stripped by
     * np_normalize() on the candidate side, hence the simple `LIKE
     * ':a feat %'` form below.
     */
    private function lookupArtistPrefixFeaturingTitle(string $artistNormalized, string $titleNormalized): ?string
    {
        if ($artistNormalized === '' || $titleNormalized === '') {
            return null;
        }

        $sql = 'SELECT id FROM media_file
                WHERE np_normalize(title) = :t
                  AND (
                      np_normalize(artist) LIKE :feat
                      OR np_normalize(artist) LIKE :ft
                      OR np_normalize(artist) LIKE :featuring
                      OR np_normalize(artist) LIKE :with
                  )
                ORDER BY id ASC
                LIMIT 1';
        $id = $this->connection()->fetchOne($sql, [
            't' => $titleNormalized,
            'feat' => $artistNormalized . ' feat %',
            'ft' => $artistNormalized . ' ft %',
            'featuring' => $artistNormalized . ' featuring %',
            'with' => $artistNormalized . ' with %',
        ]);

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * True when the (raw) title carries an explicit featuring marker —
     * "(feat. X)" / "[ft. X]" / "(featuring X)" / "(with X)" — including
     * truncated open-paren forms ("(Ft Roots Manuva" without trailing
     * `)`) when the truncation comes from Last.fm's title length cap.
     * Used as the signal to enable {@see lookupArtistPrefixFeaturingTitle()}.
     */
    private static function titleHasFeaturingMarker(string $title): bool
    {
        // Closed paren / bracket form.
        if (preg_match('/\((?:feat\.?|ft\.?|featuring|with)\s+[^)]+\)/iu', $title)) {
            return true;
        }
        if (preg_match('/\[(?:feat\.?|ft\.?|featuring|with)\s+[^\]]+\]/iu', $title)) {
            return true;
        }

        // Truncated open paren (no closing paren in the whole title).
        if (
            !preg_match('/\([^)]*\)/u', $title)
            && preg_match('/\((?:feat\.?|ft\.?|featuring|with)\s+/iu', $title)
        ) {
            return true;
        }

        return false;
    }

    /**
     * Strict couple lookup that ALSO requires album_artist to match.
     * Used as a confidence threshold for risky strips (e.g. lead-artist
     * fallback on multi-artist separators) where the relaxed tie-break
     * of {@see lookupExactArtistTitle()} would let through false-positives.
     */
    private function lookupExactArtistTitleRequiringAlbumArtist(string $artistNormalized, string $titleNormalized): ?string
    {
        $sql = 'SELECT id FROM media_file
                WHERE np_normalize(artist) = :a
                  AND np_normalize(title) = :t
                  AND np_normalize(album_artist) = :a
                ORDER BY id ASC
                LIMIT 1';
        $id = $this->connection()->fetchOne($sql, ['a' => $artistNormalized, 't' => $titleNormalized]);

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Drop a trailing or parenthesized featuring suffix from a (raw, not yet
     * normalized) artist string. Recognises `feat`, `feat.`, `ft`, `ft.`,
     * `featuring` case-insensitively. Returns the input unchanged when no
     * marker is present. Operates on the raw input because self::normalize()
     * strips parens/dots, which would defeat the patterns below.
     */
    private static function stripFeaturedArtists(string $artist): string
    {
        // 1. Strip parenthesized suffix: "(feat. …)" / "(ft. …)" / "(featuring …)".
        $stripped = preg_replace('/\s*\((?:feat\.?|ft\.?|featuring)\s+[^)]*\)\s*/iu', '', $artist) ?? $artist;
        // 2. Strip trailing form: " feat. …" / " ft. …" / " featuring …" until end.
        $stripped = preg_replace('/\s+(?:feat\.?|ft\.?|featuring)\s+.*$/iu', '', $stripped) ?? $stripped;

        return trim($stripped);
    }

    /**
     * Drop a trailing version-marker / decoration suffix from a (raw, not yet
     * normalized) title. Handles three forms — parenthesized "(Radio Edit)",
     * bracketed "[Radio Edit]", dash-separated " - Radio Edit" (ASCII -, en
     * dash, em dash). Strips master/packaging markers (radio/album/single/
     * extended/mono/stereo edit/version/mix, remaster with optional year) and
     * recording-context markers (live, acoustic, instrumental, demo, deluxe).
     * Live/acoustic/etc. is only stripped when *delimited* — `Live and Let
     * Die` in the title body remains intact. Remix is intentionally NOT
     * stripped (DJ remixes are usually distinct recordings). Operates on
     * the raw input because self::normalize() strips parens/dashes/dots
     * which would defeat the patterns below.
     */
    private static function stripVersionMarkers(string $title): string
    {
        // Order matters: longer alternatives must come first so "remastered 2011"
        // is captured by the year-aware pattern instead of just "remastered".
        // Contextual markers (live/acoustic/…) accept an optional trailing
        // qualifier so "Live at Reading 1992" / "Acoustic Version" / "Deluxe
        // Edition" all match.
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

        // Parenthesized form: " (Radio Edit)".
        $stripped = preg_replace('/\s*\(' . $markers . '\)\s*$/iu', '', $title) ?? $title;
        // Bracketed form: " [Radio Edit]".
        $stripped = preg_replace('/\s*\[' . $markers . '\]\s*$/iu', '', $stripped) ?? $stripped;
        // Dash-separated form: " - Radio Edit" (ASCII -, en dash, em dash).
        $stripped = preg_replace('/\s+[\-\x{2013}\x{2014}]\s+' . $markers . '\s*$/iu', '', $stripped) ?? $stripped;

        return trim($stripped);
    }

    /**
     * Drop a parenthesized or bracketed featuring/with suffix from a title.
     * Catches "Crazy in Love (feat. Jay-Z)", "Bad Guy (with Justin Bieber)",
     * "Some Track [featuring X]". Only the delimited form is stripped — never
     * a trailing " feat. X" without parens, which is too risky on titles
     * (real titles can legitimately contain "feat" as a word). Operates on
     * the raw input.
     */
    private static function stripFeaturingFromTitle(string $title): string
    {
        $pattern = '(?:feat\.?|ft\.?|featuring|with)\s+[^)\]]+';
        $stripped = preg_replace('/\s*\(' . $pattern . '\)\s*$/iu', '', $title) ?? $title;
        $stripped = preg_replace('/\s*\[' . $pattern . '\]\s*$/iu', '', $stripped) ?? $stripped;

        return trim($stripped);
    }

    /**
     * Drop a leading track-number prefix like "01 - ", "02_", "12-",
     * "100. " from a title — vestige of old MP3 tags where the title
     * embeds the track number. Requires a delimiter (`_`, `-`, `.`,
     * whitespace) AND a non-blank character behind, so titles like
     * "1979" or "5/4" (followed by end-of-string) are not eaten.
     */
    private static function stripTrackNumberPrefix(string $title): string
    {
        return trim(preg_replace('/^\d{1,3}[_\-.\s]+(?=\S)/u', '', $title) ?? $title);
    }

    /**
     * Drop a trailing OPEN parenthesis block when its content starts
     * with a known marker keyword — Last.fm truncates titles around 64
     * chars and leaves unbalanced parens. Abstains if the title already
     * contains a closed `(...)` group (could be legit). Conservative:
     * only strips when the open-paren content begins with a recognized
     * marker so we don't eat legit titles ending in "Foo (something".
     */
    private static function stripTruncatedParen(string $title): string
    {
        if (preg_match('/\([^)]*\)/u', $title)) {
            return $title;
        }
        $markers = '(?:feat\.?|ft\.?|featuring|with|live|acoustic|remastered|remaster|deluxe|extended|radio|album|single|mono|stereo|instrumental|demo)';

        return trim(preg_replace('/\s*\(' . $markers . '[^)]*$/iu', '', $title) ?? $title);
    }

    /**
     * Drop trailing co-artists separated by `,`, ` - `, `&`, ` and `,
     * ` et ` to keep only the lead artist (e.g. "Médine & Rounhaa" →
     * "Médine"). Returns the input unchanged if no recognized
     * separator is present. Used as a last-resort fallback when the
     * regular cascade fails.
     */
    private static function stripLeadArtist(string $artist): string
    {
        if (preg_match('/^(.*?)(?:\s*,\s*|\s+-\s+|\s*&\s*|\s+(?:and|et)\s+)/iu', $artist, $m)) {
            $lead = trim($m[1]);
            if ($lead !== '' && $lead !== $artist) {
                return $lead;
            }
        }

        return $artist;
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
            'f' => $from->getTimestamp(),
            't' => $to->getTimestamp(),
        ], [
            'f' => \Doctrine\DBAL\ParameterType::INTEGER,
            't' => \Doctrine\DBAL\ParameterType::INTEGER,
        ]);

        return $found !== false;
    }

    /**
     * Insert a scrobble row. Caller is responsible for dedup. Throws if the
     * scrobbles table does not exist (Navidrome < 0.55) or if the DB is
     * mounted read-only.
     *
     * Callers that insert in batch should wrap their loop in
     * {@see beginWriteTransaction()} / {@see commitWrite()} (or
     * {@see rollbackWrite()} on error) — autocommit per row leaves a crash
     * mid-batch with partial writes and a half-flushed WAL.
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
            [$userId, $mediaFileId, $time->getTimestamp()],
            [2 => \Doctrine\DBAL\ParameterType::INTEGER],
        );
    }

    /**
     * Open a write transaction with `BEGIN IMMEDIATE` — takes the RESERVED
     * lock right away and fails fast if another writer (Navidrome
     * accidentally restarted by Docker auto-restart, a parallel
     * `app:lastfm:process` somehow scheduled twice, etc.) is holding it.
     * `Connection::beginTransaction()` would do `BEGIN DEFERRED` which only
     * locks on the first INSERT, leaving a window for a concurrent writer.
     */
    public function beginWriteTransaction(): void
    {
        $this->connection()->executeStatement('BEGIN IMMEDIATE');
    }

    public function commitWrite(): void
    {
        $this->connection()->executeStatement('COMMIT');
    }

    public function rollbackWrite(): void
    {
        $this->connection()->executeStatement('ROLLBACK');
    }

    /**
     * Force-merge any pending WAL frames into the main DB and truncate the
     * WAL file. Should be called in a `finally` at the end of any batch
     * write run, before {@see closeWriteConnection()} — otherwise SQLite may
     * leave the WAL un-checkpointed across a restart, and Navidrome opening
     * the DB next would have to recover an inconsistent WAL (risk of
     * corruption).
     *
     * No-op when the journal mode is `DELETE` (Navidrome forces WAL by
     * default, but be defensive). Errors are swallowed because this lives in
     * a `finally` and the caller's post-action quick_check upstream is the
     * authoritative integrity check.
     */
    public function walCheckpointTruncate(): void
    {
        if ($this->connection === null) {
            return;
        }
        try {
            $this->connection->executeQuery('PRAGMA wal_checkpoint(TRUNCATE)')->fetchAssociative();
        } catch (\Throwable) {
            // best-effort
        }
    }

    /**
     * Close the underlying PDO connection so the OS file lock is released
     * and SQLite can do its own final checkpoint book-keeping. The
     * connection lazily reconnects on the next access.
     */
    public function closeWriteConnection(): void
    {
        if ($this->connection === null) {
            return;
        }
        try {
            $this->connection->close();
        } catch (\Throwable) {
            // best-effort
        }
        $this->connection = null;
    }

    /**
     * Lowercase, trim, strip Unicode diacritics, then strip every character
     * that is not a letter, digit or whitespace (collapsing repeated
     * whitespace afterwards). So "Beyoncé" matches "Beyonce", "Sigur Rós"
     * matches "Sigur Ros", "AC/DC" matches "ACDC", "Guns N' Roses" matches
     * "Guns N Roses", "P!nk" matches "Pink", "t.A.T.u." matches "tATu", etc.
     *
     * Decomposition uses NFKD (compatibility decomposition) then drops every
     * combining mark (\p{Mn}). Also exposed to SQLite as the
     * `np_normalize(value)` UDF so that the same transformation is applied
     * server-side on the indexed columns — see {@see connection()}.
     */
    public static function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $decomposed = \Normalizer::normalize($s, \Normalizer::FORM_KD);
        if ($decomposed === false) {
            // Input was not valid UTF-8 — fall back to the lowercased form.
            return $s;
        }
        $stripped = (string) preg_replace('/\p{Mn}+/u', '', $decomposed);
        // Drop punctuation/symbols (keep letters, digits, whitespace).
        $cleaned = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', '', $stripped);

        return trim((string) preg_replace('/\s+/u', ' ', $cleaned));
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

        // Expose self::normalize() as a SQLite scalar UDF so that artist/title
        // columns can be matched accent- and case-insensitively without
        // pre-computing a normalized column. Cost is one PHP callback per row
        // scanned; acceptable for libs under ~100k tracks.
        $native = $this->connection->getNativeConnection();
        if ($native instanceof \PDO) {
            $native->sqliteCreateFunction('np_normalize', [self::class, 'normalize'], 1);
        }

        // Durability + concurrency knobs:
        //  - busy_timeout: retry-on-lock window. If Navidrome got restarted
        //    by Docker auto-restart while we were writing, every INSERT
        //    waits up to 30s for the lock instead of failing instantly.
        //    Cost: zero in the happy path.
        //  - synchronous=FULL: every COMMIT waits for fsync of both the WAL
        //    page and the WAL header. Slower than NORMAL but absolutely
        //    necessary on a SQLite file that another process (Navidrome)
        //    will reopen — a power-cut / OOM-kill must not leave a torn
        //    write that corrupts the DB. This is what Navidrome itself
        //    runs by default, we just make sure to match it.
        //  - We deliberately do NOT touch journal_mode: it is persistent
        //    (stored in the DB header) and Navidrome owns that decision.
        $this->connection->executeStatement('PRAGMA busy_timeout = 30000');
        $this->connection->executeStatement('PRAGMA synchronous = FULL');

        return $this->connection;
    }
}
