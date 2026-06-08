<?php

namespace App\Service;

use App\Entity\StatsSnapshot;
use App\Filter\DateCascadeFilter;
use App\Repository\StatsSnapshotRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Aggregates the « Last.fm at a glance » payload — counters, top all-time,
 * recent scrobbles, recent loved — from the local `scrobbles` table
 * (populated by the Last.fm import). Caches the result in `stats_snapshot`
 * under the key `lastfm-stats[-<user>]`.
 *
 * Distinct from {@see LocalStatsService} which carries the windowed views
 * (7d / 30d / 90d / 12mo / all-time) used by the existing /stats page;
 * this one is the all-time « bibliothèque + dernières activités »
 * counterpart of /navidrome/stats.
 */
class LastFmStatsService
{
    public const SNAPSHOT_KEY_PREFIX = 'lastfm-stats';

    private const TOP_LIMIT = 15;
    private const RECENT_SCROBBLES_LIMIT = 100;
    private const RECENT_LOVED_LIMIT = 25;
    private const PLAYS_BY_MONTH_COUNT = 12;
    private const PLAYS_BY_WEEK_COUNT = 15;
    private const PLAYS_BY_DAY_COUNT = 15;
    private const TOP_PAGE_LIMIT = 100;

    public function __construct(
        private readonly Connection $connection,
        private readonly StatsSnapshotRepository $snapshots,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function compute(?string $user = null): array
    {
        $user = $this->resolveUser($user);
        $library = $this->libraryCounts($user);
        $bounds = $this->scrobbleBounds($user);

        $today = new \DateTimeImmutable('today');
        $periodFrom = $today->modify('-12 months');
        $alltime = StreakStats::compute($this->listenedDays($user, null), $today);
        $period = StreakStats::compute($this->listenedDays($user, $periodFrom), $today);

        $data = [
            'computed_at' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'user' => $user,
            'library' => $library,
            'loved_count' => $this->lovedCount($user),
            'first_scrobble_at' => $bounds['first'],
            'last_scrobble_at' => $bounds['last'],
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
            'plays_by_month' => $this->playsByMonth(self::PLAYS_BY_MONTH_COUNT, $user),
            'plays_by_week' => $this->playsByWeek(self::PLAYS_BY_WEEK_COUNT, $user),
            'plays_by_day' => $this->playsByDay(self::PLAYS_BY_DAY_COUNT, $user),
            'top_artists' => $this->topArtists($user, self::TOP_LIMIT),
            'top_tracks' => $this->topTracks($user, self::TOP_LIMIT),
            'top_albums' => $this->topAlbums($user, self::TOP_LIMIT),
            // Top 100 sans filtre, pré-calculés pour les pages /lastfm/top-*
            // (lecture instantanée depuis le snapshot quand l'utilisateur
            // n'a posé aucun filtre date). Les vues filtrées re-tirent en
            // live — le filtre réduit la volumétrie donc la requête est
            // rapide de toute façon.
            'top_tracks_alltime' => $this->topTracksWithDates($user, null, null, null, self::TOP_PAGE_LIMIT),
            'top_albums_alltime' => $this->topAlbumsWithDates($user, null, null, null, self::TOP_PAGE_LIMIT),
            'top_artists_alltime' => $this->topArtistsWithDates($user, null, null, null, self::TOP_PAGE_LIMIT),
            'recent_scrobbles' => $this->recentScrobbles($user, self::RECENT_SCROBBLES_LIMIT),
            'recent_loved' => $this->recentLoved($user, self::RECENT_LOVED_LIMIT),
        ];

        $key = $this->snapshotKey($user);
        $snapshot = $this->snapshots->findOneByPeriod($key);
        if ($snapshot === null) {
            $snapshot = new StatsSnapshot($key);
            $this->em->persist($snapshot);
        }
        $snapshot->setData($data);
        $this->em->flush();

        return $data;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(?string $user = null): ?array
    {
        $snapshot = $this->snapshots->findOneByPeriod($this->snapshotKey($this->resolveUser($user)));
        if ($snapshot === null) {
            return null;
        }

        /** @var array<string, mixed> */
        return $snapshot->getData();
    }

    /**
     * Resolve the user to filter on. Explicit value wins ; otherwise auto-pick
     * the most-scrobbled `lastfm_user` from the local DB so the CLI and the
     * UI default to the dominant user when the LASTFM_USER env is empty
     * (which is the case in fresh installs). Returns null only when there
     * isn't a single scrobble in the DB.
     */
    public function resolveUser(?string $user): ?string
    {
        if ($user !== null && $user !== '') {
            return $user;
        }

        $row = $this->connection->fetchOne(
            'SELECT lastfm_user FROM scrobbles
              WHERE lastfm_user != \'\'
              GROUP BY lastfm_user
              ORDER BY COUNT(*) DESC
              LIMIT 1',
        );

        return is_string($row) && $row !== '' ? $row : null;
    }

    public function snapshotKey(?string $user): string
    {
        return ($user !== null && $user !== '')
            ? self::SNAPSHOT_KEY_PREFIX . '-' . $user
            : self::SNAPSHOT_KEY_PREFIX;
    }

    /**
     * @return array{scrobbles: int, tracks: int, artists: int, albums: int}
     */
    private function libraryCounts(?string $user): array
    {
        [$where, $params] = $this->buildWhere($user);
        $whereClause = $where !== '' ? ' WHERE ' . $where : '';

        $scrobbles = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM scrobbles' . $whereClause,
            $params,
        );
        $artists = (int) $this->connection->fetchOne(
            "SELECT COUNT(DISTINCT artist) FROM scrobbles"
            . ($where !== '' ? ' WHERE ' . $where . " AND artist != ''" : " WHERE artist != ''"),
            $params,
        );
        $tracks = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM (SELECT 1 FROM scrobbles'
            . $whereClause
            . ' GROUP BY artist, title)',
            $params,
        );
        $albums = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM (SELECT 1 FROM scrobbles"
            . ($where !== '' ? ' WHERE ' . $where . " AND album IS NOT NULL AND album != ''" : " WHERE album IS NOT NULL AND album != ''")
            . ' GROUP BY artist, album)',
            $params,
        );

        return [
            'scrobbles' => $scrobbles,
            'tracks' => $tracks,
            'artists' => $artists,
            'albums' => $albums,
        ];
    }

    /**
     * Count distinct (artist, title) pairs flagged as loved by at least one
     * scrobble. Two scrobbles of the same loved track count as one — the
     * Last.fm import propagates the `loved` flag on every fetched row, so
     * de-duplicating on (artist, title) gives the actual loved-track count
     * rather than the number of « loved scrobbles ».
     */
    private function lovedCount(?string $user): int
    {
        [$where, $params] = $this->buildWhere($user);
        $sql = 'SELECT COUNT(*) FROM (SELECT 1 FROM scrobbles WHERE loved = 1'
            . ($where !== '' ? ' AND ' . $where : '')
            . ' GROUP BY artist, title)';

        return (int) $this->connection->fetchOne($sql, $params);
    }

    /**
     * @return array{first: ?string, last: ?string}
     */
    private function scrobbleBounds(?string $user): array
    {
        [$where, $params] = $this->buildWhere($user);
        $row = $this->connection->fetchAssociative(
            'SELECT MIN(played_at) AS first, MAX(played_at) AS last FROM scrobbles'
            . ($where !== '' ? ' WHERE ' . $where : ''),
            $params,
        );
        if ($row === false) {
            return ['first' => null, 'last' => null];
        }

        return [
            'first' => $row['first'] !== null ? (string) $row['first'] : null,
            'last' => $row['last'] !== null ? (string) $row['last'] : null,
        ];
    }

    /**
     * Open-ended monthly play series starting at `$sinceMonth` (`YYYY-MM`,
     * inclusive) and running up to and including the current month, with
     * missing months filled at zero so the consumer always gets a contiguous
     * axis. Counterpart of {@see playsByMonth()} but without the rolling
     * window — feeds the Last.fm ↔ Navidrome disparity panel which needs to
     * compare every month since the library exists, not the last 12.
     *
     * @return list<array{month: string, plays: int}>
     */
    public function playsByMonthSince(string $sinceMonth, ?string $user): array
    {
        $from = \DateTimeImmutable::createFromFormat('!Y-m', $sinceMonth);
        if ($from === false) {
            return [];
        }
        $from = $from->modify('first day of this month')->setTime(0, 0);
        $today = (new \DateTimeImmutable('today'))->modify('first day of this month');
        if ($from > $today) {
            return [];
        }

        [$where, $params] = $this->buildWhere($user);
        $clauses = ['played_at >= :from'];
        if ($where !== '') {
            $clauses[] = $where;
        }
        $params['from'] = $from->format('Y-m-d H:i:s');

        $sql = "SELECT strftime('%Y-%m', played_at) AS month, COUNT(*) AS plays
                FROM scrobbles
                WHERE " . implode(' AND ', $clauses)
            . ' GROUP BY month ORDER BY month ASC';

        /** @var list<array{month: string, plays: int}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params);
        $byMonth = [];
        foreach ($rows as $r) {
            $byMonth[(string) $r['month']] = (int) $r['plays'];
        }

        $out = [];
        $cursor = $from;
        while ($cursor <= $today) {
            $key = $cursor->format('Y-m');
            $out[] = ['month' => $key, 'plays' => $byMonth[$key] ?? 0];
            $cursor = $cursor->modify('+1 month');
        }

        return $out;
    }

    /**
     * @return list<array{month: string, plays: int}>
     */
    private function playsByMonth(int $monthsBack, ?string $user): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get()));
        $from = $now->modify('first day of this month')->setTime(0, 0)
            ->modify(sprintf('-%d months', max(0, $monthsBack - 1)));

        [$where, $params] = $this->buildWhere($user);
        $clauses = ['played_at >= :from'];
        if ($where !== '') {
            $clauses[] = $where;
        }
        $params['from'] = $from->format('Y-m-d H:i:s');

        $sql = "SELECT strftime('%Y-%m', played_at) AS month, COUNT(*) AS plays
                FROM scrobbles
                WHERE " . implode(' AND ', $clauses)
            . ' GROUP BY month ORDER BY month ASC';

        /** @var list<array{month: string, plays: int}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params);
        $byMonth = [];
        foreach ($rows as $r) {
            $byMonth[(string) $r['month']] = (int) $r['plays'];
        }

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
     * Plays per Monday-started week over the last $weeksBack weeks. The
     * `week` key is the Monday date (Y-m-d) of each bucket.
     *
     * @return list<array{week: string, plays: int}>
     */
    private function playsByWeek(int $weeksBack, ?string $user): array
    {
        $monday = (new \DateTimeImmutable('monday this week'))->setTime(0, 0);
        $from = $monday->modify(sprintf('-%d weeks', max(0, $weeksBack - 1)));

        [$where, $params] = $this->buildWhere($user);
        $clauses = ['played_at >= :from'];
        if ($where !== '') {
            $clauses[] = $where;
        }
        $params['from'] = $from->format('Y-m-d H:i:s');

        // SQLite weekday: 0=Sunday..6=Saturday → shift back to Monday.
        $sql = "SELECT date(played_at, '-' || ((strftime('%w', played_at) + 6) % 7) || ' days') AS week,
                       COUNT(*) AS plays
                FROM scrobbles
                WHERE " . implode(' AND ', $clauses)
            . ' GROUP BY week ORDER BY week ASC';

        /** @var list<array{week: string, plays: int}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params);
        $byWeek = [];
        foreach ($rows as $r) {
            $byWeek[(string) $r['week']] = (int) $r['plays'];
        }

        $out = [];
        $cursor = $from;
        for ($i = 0; $i < $weeksBack; $i++) {
            $key = $cursor->format('Y-m-d');
            $out[] = ['week' => $key, 'plays' => $byWeek[$key] ?? 0];
            $cursor = $cursor->modify('+1 week');
        }

        return $out;
    }

    /**
     * Plays per day over the last $daysBack days (today included).
     *
     * @return list<array{day: string, plays: int}>
     */
    private function playsByDay(int $daysBack, ?string $user): array
    {
        $from = (new \DateTimeImmutable('today'))->modify(sprintf('-%d days', max(0, $daysBack - 1)));

        [$where, $params] = $this->buildWhere($user);
        $clauses = ['played_at >= :from'];
        if ($where !== '') {
            $clauses[] = $where;
        }
        $params['from'] = $from->format('Y-m-d H:i:s');

        $sql = "SELECT date(played_at) AS day, COUNT(*) AS plays
                FROM scrobbles
                WHERE " . implode(' AND ', $clauses)
            . ' GROUP BY day ORDER BY day ASC';

        /** @var list<array{day: string, plays: int}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params);
        $byDay = [];
        foreach ($rows as $r) {
            $byDay[(string) $r['day']] = (int) $r['plays'];
        }

        $out = [];
        $cursor = $from;
        for ($i = 0; $i < $daysBack; $i++) {
            $key = $cursor->format('Y-m-d');
            $out[] = ['day' => $key, 'plays' => $byDay[$key] ?? 0];
            $cursor = $cursor->modify('+1 day');
        }

        return $out;
    }

    /**
     * @return list<array{artist: string, plays: int}>
     */
    private function topArtists(?string $user, int $limit): array
    {
        [$where, $params] = $this->buildWhere($user);
        $clauses = ["artist != ''"];
        if ($where !== '') {
            $clauses[] = $where;
        }
        $sql = 'SELECT artist, COUNT(*) AS plays FROM scrobbles WHERE '
            . implode(' AND ', $clauses)
            . ' GROUP BY artist ORDER BY plays DESC, artist ASC LIMIT ' . $limit;

        /** @var list<array{artist: string, plays: int}> */
        return $this->connection->fetchAllAssociative($sql, $params);
    }

    /**
     * Top artists by play volume on a year / month / day cascade, with
     * the artist's first and last scrobble inside the window (both as
     * `YYYY-MM-DD HH:MM:SS` strings from `played_at`). Feeds the
     * `/lastfm/top-artists` page.
     *
     * @return list<array{artist: string, plays: int, first_played_at: string, last_played_at: string}>
     */
    public function topArtistsWithDates(
        ?string $user,
        ?int $year,
        ?int $month,
        ?int $day,
        int $limit,
    ): array {
        [$where, $params] = $this->buildWhere($user);
        $clauses = ["artist != ''"];
        if ($where !== '') {
            $clauses[] = $where;
        }
        if ($year !== null) {
            if ($day !== null && $month !== null) {
                $clauses[] = "strftime('%Y-%m-%d', played_at) = :ymd";
                $params['ymd'] = sprintf('%04d-%02d-%02d', $year, $month, $day);
            } elseif ($month !== null) {
                $clauses[] = "strftime('%Y-%m', played_at) = :ym";
                $params['ym'] = sprintf('%04d-%02d', $year, $month);
            } else {
                $clauses[] = "strftime('%Y', played_at) = :y";
                $params['y'] = (string) $year;
            }
        }
        $sql = 'SELECT artist,
                       COUNT(*) AS plays,
                       MIN(played_at) AS first_played_at,
                       MAX(played_at) AS last_played_at
                FROM scrobbles WHERE ' . implode(' AND ', $clauses)
            . ' GROUP BY artist ORDER BY plays DESC, artist ASC LIMIT ' . max(1, $limit);

        /** @var list<array{artist: string, plays: int, first_played_at: ?string, last_played_at: ?string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params);

        return array_map(
            static fn (array $r): array => [
                'artist' => (string) $r['artist'],
                'plays' => (int) $r['plays'],
                'first_played_at' => (string) ($r['first_played_at'] ?? ''),
                'last_played_at' => (string) ($r['last_played_at'] ?? ''),
            ],
            $rows,
        );
    }

    /**
     * Top albums by scrobble volume on a year / month / day cascade, with
     * first / last play. Feeds /lastfm/top-albums. Ignore rows with an
     * empty album (Last.fm sometimes omits the album tag on loose
     * scrobbles — folding them under "no album" would produce a single
     * unfocused line at the top).
     *
     * @return list<array{artist: string, album: string, plays: int, first_played_at: string, last_played_at: string}>
     */
    public function topAlbumsWithDates(
        ?string $user,
        ?int $year,
        ?int $month,
        ?int $day,
        int $limit,
    ): array {
        [$where, $params] = $this->buildWhere($user);
        $clauses = ["album IS NOT NULL", "album != ''"];
        if ($where !== '') {
            $clauses[] = $where;
        }
        self::applyDateCascade($clauses, $params, $year, $month, $day);

        $sql = 'SELECT artist, album,
                       COUNT(*) AS plays,
                       MIN(played_at) AS first_played_at,
                       MAX(played_at) AS last_played_at
                FROM scrobbles WHERE ' . implode(' AND ', $clauses)
            . ' GROUP BY artist, album ORDER BY plays DESC, album ASC LIMIT ' . max(1, $limit);

        /** @var list<array{artist: string, album: string, plays: int, first_played_at: ?string, last_played_at: ?string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params);

        return array_map(
            static fn (array $r): array => [
                'artist' => (string) $r['artist'],
                'album' => (string) $r['album'],
                'plays' => (int) $r['plays'],
                'first_played_at' => (string) ($r['first_played_at'] ?? ''),
                'last_played_at' => (string) ($r['last_played_at'] ?? ''),
            ],
            $rows,
        );
    }

    /**
     * Top tracks by scrobble volume on a year / month / day cascade, with
     * first / last play and a representative album per (artist, title).
     * Same `album` resolution as the existing private {@see topTracks()}
     * — pick the first non-empty album spelling we find for the couple,
     * so the column isn't blank when Last.fm omitted the tag on some plays.
     *
     * @return list<array{artist: string, title: string, album: ?string, plays: int, first_played_at: string, last_played_at: string}>
     */
    public function topTracksWithDates(
        ?string $user,
        ?int $year,
        ?int $month,
        ?int $day,
        int $limit,
    ): array {
        [$where, $params] = $this->buildWhere($user);
        $clauses = [];
        if ($where !== '') {
            $clauses[] = $where;
        }
        self::applyDateCascade($clauses, $params, $year, $month, $day);

        $whereSql = $clauses !== [] ? ' WHERE ' . implode(' AND ', $clauses) : '';
        $subWhere = $where !== '' ? ' AND ' . str_replace('lastfm_user', 's2.lastfm_user', $where) : '';

        $sql = "SELECT s.artist, s.title,
                       (SELECT s2.album FROM scrobbles s2
                         WHERE s2.artist = s.artist AND s2.title = s.title$subWhere
                           AND s2.album IS NOT NULL AND s2.album != ''
                         LIMIT 1) AS album,
                       COUNT(*) AS plays,
                       MIN(s.played_at) AS first_played_at,
                       MAX(s.played_at) AS last_played_at
                FROM scrobbles s$whereSql
                GROUP BY s.artist, s.title
                ORDER BY plays DESC, s.title ASC LIMIT " . max(1, $limit);

        /** @var list<array{artist: string, title: string, album: ?string, plays: int, first_played_at: ?string, last_played_at: ?string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, $params);

        return array_map(
            static fn (array $r): array => [
                'artist' => (string) $r['artist'],
                'title' => (string) $r['title'],
                'album' => $r['album'] !== null ? (string) $r['album'] : null,
                'plays' => (int) $r['plays'],
                'first_played_at' => (string) ($r['first_played_at'] ?? ''),
                'last_played_at' => (string) ($r['last_played_at'] ?? ''),
            ],
            $rows,
        );
    }

    /**
     * Adds the cascade clause from {@see DateCascadeFilter::toSqlClause()}
     * to the given clauses + params arrays, mirroring the {@see buildWhere()}
     * mutate-in-place pattern. No-op when `$year` is null.
     *
     * @param list<string> $clauses
     * @param array<string, mixed> $params
     */
    private static function applyDateCascade(array &$clauses, array &$params, ?int $year, ?int $month, ?int $day): void
    {
        $c = DateCascadeFilter::toSqlClause($year, $month, $day, 'played_at');
        if ($c === null) {
            return;
        }
        $clauses[] = $c['clause'];
        $params[$c['paramName']] = $c['paramValue'];
    }

    /**
     * @return list<array{artist: string, title: string, album: ?string, plays: int}>
     */
    private function topTracks(?string $user, int $limit): array
    {
        [$where, $params] = $this->buildWhere($user);
        $sql = 'SELECT artist, title,
                       (SELECT album FROM scrobbles s2
                        WHERE s2.artist = s.artist AND s2.title = s.title'
            . ($where !== '' ? ' AND ' . str_replace('lastfm_user', 's2.lastfm_user', $where) : '')
            . '          AND s2.album IS NOT NULL AND s2.album != ""
                        LIMIT 1) AS album,
                       COUNT(*) AS plays
                FROM scrobbles s'
            . ($where !== '' ? ' WHERE ' . $where : '')
            . ' GROUP BY artist, title ORDER BY plays DESC, title ASC LIMIT ' . $limit;

        /** @var list<array{artist: string, title: string, album: ?string, plays: int}> */
        return $this->connection->fetchAllAssociative($sql, $params);
    }

    /**
     * @return list<array{artist: string, album: string, plays: int}>
     */
    private function topAlbums(?string $user, int $limit): array
    {
        [$where, $params] = $this->buildWhere($user);
        $clauses = ["album IS NOT NULL", "album != ''"];
        if ($where !== '') {
            $clauses[] = $where;
        }
        $sql = 'SELECT artist, album, COUNT(*) AS plays FROM scrobbles WHERE '
            . implode(' AND ', $clauses)
            . ' GROUP BY artist, album ORDER BY plays DESC, album ASC LIMIT ' . $limit;

        /** @var list<array{artist: string, album: string, plays: int}> */
        return $this->connection->fetchAllAssociative($sql, $params);
    }

    /**
     * @return list<array{artist: string, title: string, album: ?string, played_at: string, loved: bool}>
     */
    private function recentScrobbles(?string $user, int $limit): array
    {
        [$where, $params] = $this->buildWhere($user);
        $sql = 'SELECT artist, title, album, played_at, loved FROM scrobbles'
            . ($where !== '' ? ' WHERE ' . $where : '')
            . ' ORDER BY played_at DESC LIMIT ' . $limit;

        $rows = $this->connection->fetchAllAssociative($sql, $params);

        return array_map(static fn (array $r): array => [
            'artist' => (string) ($r['artist'] ?? ''),
            'title' => (string) ($r['title'] ?? ''),
            'album' => $r['album'] !== null ? (string) $r['album'] : null,
            'played_at' => (string) $r['played_at'],
            'loved' => (bool) $r['loved'],
        ], $rows);
    }

    /**
     * @return list<array{artist: string, title: string, album: ?string, played_at: string}>
     */
    private function recentLoved(?string $user, int $limit): array
    {
        [$where, $params] = $this->buildWhere($user);
        $clauses = ['loved = 1'];
        if ($where !== '') {
            $clauses[] = $where;
        }
        $sql = 'SELECT artist, title,
                       (SELECT album FROM scrobbles s2 WHERE s2.artist = s.artist AND s2.title = s.title AND s2.album IS NOT NULL AND s2.album != "" LIMIT 1) AS album,
                       MAX(played_at) AS played_at
                FROM scrobbles s WHERE '
            . implode(' AND ', $clauses)
            . ' GROUP BY artist, title ORDER BY played_at DESC LIMIT ' . $limit;

        $rows = $this->connection->fetchAllAssociative($sql, $params);

        return array_map(static fn (array $r): array => [
            'artist' => (string) ($r['artist'] ?? ''),
            'title' => (string) ($r['title'] ?? ''),
            'album' => $r['album'] !== null ? (string) $r['album'] : null,
            'played_at' => (string) $r['played_at'],
        ], $rows);
    }

    /**
     * Distinct calendar dates (Y-m-d) where $user scrobbled at least once,
     * filtered on `played_at >= :from` when $from is provided.
     *
     * @return list<string>
     */
    private function listenedDays(?string $user, ?\DateTimeImmutable $from): array
    {
        [$where, $params] = $this->buildWhere($user);
        $clauses = $where !== '' ? [$where] : [];
        if ($from !== null) {
            $clauses[] = 'played_at >= :from';
            $params['from'] = $from->format('Y-m-d H:i:s');
        }
        $sql = "SELECT DISTINCT date(played_at) AS d FROM scrobbles"
            . ($clauses !== [] ? ' WHERE ' . implode(' AND ', $clauses) : '')
            . ' ORDER BY d';

        $rows = $this->connection->fetchAllAssociative($sql, $params);

        return array_map(static fn (array $r): string => (string) $r['d'], $rows);
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function buildWhere(?string $user): array
    {
        if ($user === null || $user === '') {
            return ['', []];
        }

        return ['lastfm_user = :user', ['user' => $user]];
    }
}
