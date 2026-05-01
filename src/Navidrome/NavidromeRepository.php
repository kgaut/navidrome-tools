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
    public function getTotalPlays(?\DateTimeInterface $from, ?\DateTimeInterface $to): int
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
    public function getDistinctTracksPlayed(?\DateTimeInterface $from, ?\DateTimeInterface $to): int
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
    public function getTopArtists(?\DateTimeInterface $from, ?\DateTimeInterface $to, int $limit): array
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
    public function getTopTracksWithDetails(?\DateTimeInterface $from, ?\DateTimeInterface $to, int $limit): array
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
     * Top $topN artists by total plays over the last $monthsBack months,
     * with a per-month timeseries for each.
     *
     * @return list<array{artist: string, total: int, series: list<array{month: string, plays: int}>}>
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

        // Top artists in window
        $topRows = $this->connection()->fetchAllAssociative(
            "SELECT mf.artist AS artist, COUNT(*) AS plays
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
            $series = [];
            foreach ($months as $m) {
                $series[] = ['month' => $m, 'plays' => $byArtistMonth[$artist][$m] ?? 0];
            }
            $out[] = ['artist' => $artist, 'total' => (int) $top['plays'], 'series' => $series];
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
