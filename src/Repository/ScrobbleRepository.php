<?php

namespace App\Repository;

use App\Entity\Scrobble;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Scrobble> */
class ScrobbleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Scrobble::class);
    }

    public function countByUser(string $user): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.lastfmUser = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Returns the most recent played_at for the given user, or null if no
     * scrobble has been stored yet. Used by app:lastfm:fetch for smart date.
     */
    public function getLastPlayedAt(string $user): ?\DateTimeImmutable
    {
        $result = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT MAX(played_at) FROM scrobbles WHERE lastfm_user = ?',
            [$user],
        );

        if ($result === false || $result === null) {
            return null;
        }

        return new \DateTimeImmutable((string) $result, new \DateTimeZone('UTC'));
    }

    /**
     * Insert a scrobble using raw DBAL INSERT OR IGNORE for performance and
     * idempotency. Returns true when the row was inserted, false when it was
     * rejected by the unique constraint (duplicate).
     */
    public function insertOrIgnore(
        string $user,
        string $artist,
        string $title,
        ?string $album,
        ?string $albumArtist,
        ?string $mbidTrack,
        ?string $mbidArtist,
        ?string $mbidAlbum,
        \DateTimeImmutable $playedAt,
        bool $loved,
        ?string $imageUrl,
        string $fetchedAt,
    ): bool {
        $utc = new \DateTimeZone('UTC');

        $affected = $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT OR IGNORE INTO scrobbles
                (lastfm_user, artist, title, album, album_artist,
                 mbid_track, mbid_artist, mbid_album,
                 played_at, loved, image_url, fetched_at)
             VALUES
                (:user, :artist, :title, :album, :album_artist,
                 :mbid_track, :mbid_artist, :mbid_album,
                 :played_at, :loved, :image_url, :fetched_at)',
            [
                'user' => $user,
                'artist' => $artist,
                'title' => $title,
                'album' => $album,
                'album_artist' => $albumArtist,
                'mbid_track' => $mbidTrack,
                'mbid_artist' => $mbidArtist,
                'mbid_album' => $mbidAlbum,
                'played_at' => $playedAt->setTimezone($utc)->format('Y-m-d H:i:s'),
                'loved' => $loved ? 1 : 0,
                'image_url' => $imageUrl,
                'fetched_at' => $fetchedAt,
            ],
        );

        return $affected === 1;
    }

    /**
     * Flip `loved=1` on every scrobble of (user, track). MBID match wins
     * when present (Last.fm and Navidrome agree on MusicBrainz ids when
     * tagged), case-insensitive (artist, title) otherwise. Returns the
     * number of rows actually flipped — 0 when the track has no matching
     * scrobble (loved-but-never-scrobbled), or when every matching row
     * was already loved.
     */
    public function markLoved(string $user, string $artist, string $title, ?string $mbidTrack): int
    {
        $conn = $this->getEntityManager()->getConnection();

        if ($mbidTrack !== null && $mbidTrack !== '') {
            $affected = (int) $conn->executeStatement(
                'UPDATE scrobbles SET loved = 1
                 WHERE lastfm_user = :u AND loved = 0 AND mbid_track = :mbid',
                ['u' => $user, 'mbid' => $mbidTrack],
            );
            if ($affected > 0) {
                return $affected;
            }
        }

        return (int) $conn->executeStatement(
            'UPDATE scrobbles SET loved = 1
             WHERE lastfm_user = :u AND loved = 0
               AND LOWER(artist) = LOWER(:a) AND LOWER(title) = LOWER(:t)',
            ['u' => $user, 'a' => $artist, 't' => $title],
        );
    }

    /**
     * Returns MIN/MAX played_at for the given user (or all users when null).
     *
     * @return array{first: ?\DateTimeImmutable, last: ?\DateTimeImmutable}
     */
    public function getScrobbleBounds(?string $user): array
    {
        $sql = 'SELECT MIN(played_at) AS first, MAX(played_at) AS last FROM scrobbles';
        $params = [];
        if ($user !== null && $user !== '') {
            $sql .= ' WHERE lastfm_user = ?';
            $params[] = $user;
        }

        $row = $this->getEntityManager()->getConnection()->fetchAssociative($sql, $params);
        if ($row === false || $row['first'] === null) {
            return ['first' => null, 'last' => null];
        }

        $utc = new \DateTimeZone('UTC');
        return [
            'first' => new \DateTimeImmutable((string) $row['first'], $utc),
            'last' => new \DateTimeImmutable((string) $row['last'], $utc),
        ];
    }

    /**
     * Count distinct (artist, title) pairs flagged as loved.
     */
    public function countLoved(?string $user): int
    {
        $sql = 'SELECT COUNT(*) FROM (SELECT 1 FROM scrobbles WHERE loved = 1';
        $params = [];
        if ($user !== null && $user !== '') {
            $sql .= ' AND lastfm_user = ?';
            $params[] = $user;
        }
        $sql .= ' GROUP BY artist, title)';

        return (int) $this->getEntityManager()->getConnection()->fetchOne($sql, $params);
    }

    /**
     * Count distinct non-empty artist values.
     */
    public function countDistinctArtists(?string $user): int
    {
        $sql = "SELECT COUNT(DISTINCT artist) FROM scrobbles WHERE artist != ''";
        $params = [];
        if ($user !== null && $user !== '') {
            $sql .= ' AND lastfm_user = ?';
            $params[] = $user;
        }

        return (int) $this->getEntityManager()->getConnection()->fetchOne($sql, $params);
    }

    /**
     * Count distinct (artist, title) pairs — unique tracks regardless of play count.
     */
    public function countDistinctTracks(?string $user): int
    {
        $sql = 'SELECT COUNT(*) FROM (SELECT 1 FROM scrobbles WHERE 1=1';
        $params = [];
        if ($user !== null && $user !== '') {
            $sql .= ' AND lastfm_user = ?';
            $params[] = $user;
        }
        $sql .= ' GROUP BY artist, title)';

        return (int) $this->getEntityManager()->getConnection()->fetchOne($sql, $params);
    }

    /**
     * True if (user, artist, title) has at least one scrobble in the DB,
     * regardless of its current loved state. Lets the loved-sync count
     * « known by Navidrome-tools » as matched even when every existing
     * scrobble was already flagged.
     */
    public function hasScrobble(string $user, string $artist, string $title, ?string $mbidTrack): bool
    {
        $conn = $this->getEntityManager()->getConnection();

        if ($mbidTrack !== null && $mbidTrack !== '') {
            $found = $conn->fetchOne(
                'SELECT 1 FROM scrobbles WHERE lastfm_user = :u AND mbid_track = :mbid LIMIT 1',
                ['u' => $user, 'mbid' => $mbidTrack],
            );
            if ($found !== false) {
                return true;
            }
        }

        $found = $conn->fetchOne(
            'SELECT 1 FROM scrobbles WHERE lastfm_user = :u
              AND LOWER(artist) = LOWER(:a) AND LOWER(title) = LOWER(:t) LIMIT 1',
            ['u' => $user, 'a' => $artist, 't' => $title],
        );

        return $found !== false;
    }

    /**
     * Distinct years (YYYY, descending) present in the `scrobbles` table —
     * feeds the year `<select>` of the scrobble history page so the user
     * can only pick years that actually contain scrobbles.
     *
     * @return list<string>
     */
    public function availableYears(?string $user): array
    {
        $sql = "SELECT DISTINCT strftime('%Y', played_at) AS y FROM scrobbles";
        $params = [];
        if ($user !== null && $user !== '') {
            $sql .= ' WHERE lastfm_user = ?';
            $params[] = $user;
        }
        $sql .= ' ORDER BY y DESC';
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, $params);

        $out = [];
        foreach ($rows as $r) {
            $year = (string) $r['y'];
            if ($year !== '') {
                $out[] = $year;
            }
        }

        return $out;
    }

    /**
     * Filtered scrobble listing for the history page. LEFT-joins
     * `scrobble_sync` (target = navidrome) so each row carries its match
     * status / strategy / target_id alongside the raw scrobble fields.
     *
     * Filters silently degrade on inconsistency: `day` is ignored without
     * `month`+`year`, `month` without `year`. Empty strings are treated
     * as absent. Ordered `played_at DESC`.
     *
     * @param array{
     *     lastfm_user?: ?string,
     *     year?: ?string, month?: ?string, day?: ?string,
     *     artist?: ?string, title?: ?string,
     *     status?: ?string
     * } $filters
     *
     * @return list<array{
     *     id: int, artist: string, album: ?string, title: string,
     *     mbid_track: ?string, mbid_artist: ?string, mbid_album: ?string,
     *     image_url: ?string, loved: int, played_at: string,
     *     sync_status: ?string, sync_strategy: ?string, sync_target_id: ?string
     * }>
     */
    public function findRecentWithSyncStatus(array $filters, int $limit, int $offset): array
    {
        [$where, $params] = self::buildFilterClauses($filters);
        $sql = "SELECT s.id, s.artist, s.album, s.title,
                       s.mbid_track, s.mbid_artist, s.mbid_album,
                       s.image_url, s.loved, s.played_at,
                       ss.status AS sync_status, ss.strategy AS sync_strategy, ss.target_id AS sync_target_id
                FROM scrobbles s
                LEFT JOIN scrobble_sync ss ON ss.scrobble_id = s.id AND ss.target = 'navidrome'";
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }
        $sql .= ' ORDER BY s.played_at DESC, s.id DESC LIMIT ? OFFSET ?';
        $params[] = max(1, $limit);
        $params[] = max(0, $offset);

        /** @var list<array{
         *     id: int, artist: string, album: ?string, title: string,
         *     mbid_track: ?string, mbid_artist: ?string, mbid_album: ?string,
         *     image_url: ?string, loved: int, played_at: string,
         *     sync_status: ?string, sync_strategy: ?string, sync_target_id: ?string
         * }> $rows */
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, $params);

        return $rows;
    }

    /**
     * Total count of scrobbles matching the same filters as
     * {@see findRecentWithSyncStatus()}. Joins `scrobble_sync` only when
     * the `status` filter is non-empty and not `all` — keeps the unfiltered
     * COUNT fast on the indexed `(played_at)` path.
     *
     * @param array<string, ?string> $filters
     */
    public function countWithFilters(array $filters): int
    {
        [$where, $params] = self::buildFilterClauses($filters);
        $needsJoin = isset($filters['status']) && $filters['status'] !== '' && $filters['status'] !== 'all';
        $sql = 'SELECT COUNT(*) FROM scrobbles s';
        if ($needsJoin) {
            $sql .= " LEFT JOIN scrobble_sync ss ON ss.scrobble_id = s.id AND ss.target = 'navidrome'";
        }
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }

        return (int) $this->getEntityManager()->getConnection()->fetchOne($sql, $params);
    }

    /**
     * Translates a filter array into a WHERE clause + positional params.
     * Returns `['', []]` when nothing applies. Status filters are emitted
     * against the LEFT-joined `ss.status`, with the special case
     * `status=unmatched` also matching scrobbles that have no `scrobble_sync`
     * row at all yet (NULL after LEFT JOIN) — those *are* unmatched from
     * the user's point of view.
     *
     * Public for unit-testability only — non-API surface, no BC guarantee.
     *
     * @param array<string, ?string> $filters
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public static function buildFilterClauses(array $filters): array
    {
        $clauses = [];
        $params = [];

        $user = $filters['lastfm_user'] ?? null;
        if ($user !== null && $user !== '') {
            $clauses[] = 's.lastfm_user = ?';
            $params[] = $user;
        }

        $year = self::asYear($filters['year'] ?? null);
        $month = $year !== null ? self::asMonth($filters['month'] ?? null) : null;
        $day = $month !== null ? self::asDay($filters['day'] ?? null) : null;

        if ($year !== null) {
            if ($day !== null) {
                $clauses[] = "strftime('%Y-%m-%d', s.played_at) = ?";
                $params[] = sprintf('%04d-%02d-%02d', $year, $month, $day);
            } elseif ($month !== null) {
                $clauses[] = "strftime('%Y-%m', s.played_at) = ?";
                $params[] = sprintf('%04d-%02d', $year, $month);
            } else {
                $clauses[] = "strftime('%Y', s.played_at) = ?";
                $params[] = (string) $year;
            }
        }

        $artist = isset($filters['artist']) ? trim((string) $filters['artist']) : '';
        if ($artist !== '') {
            $clauses[] = 'LOWER(s.artist) LIKE LOWER(?)';
            $params[] = '%' . $artist . '%';
        }
        $title = isset($filters['title']) ? trim((string) $filters['title']) : '';
        if ($title !== '') {
            $clauses[] = 'LOWER(s.title) LIKE LOWER(?)';
            $params[] = '%' . $title . '%';
        }

        $status = isset($filters['status']) ? trim((string) $filters['status']) : '';
        if ($status !== '' && $status !== 'all') {
            if ($status === 'unmatched') {
                $clauses[] = "(ss.status = 'unmatched' OR ss.status IS NULL)";
            } else {
                $clauses[] = 'ss.status = ?';
                $params[] = $status;
            }
        }

        return [implode(' AND ', $clauses), $params];
    }

    private static function asYear(mixed $value): ?int
    {
        if (!is_string($value) || !preg_match('/^\d{4}$/', $value)) {
            return null;
        }

        return (int) $value;
    }

    private static function asMonth(mixed $value): ?int
    {
        if (!is_string($value) || !preg_match('/^\d{1,2}$/', $value)) {
            return null;
        }
        $n = (int) $value;

        return $n >= 1 && $n <= 12 ? $n : null;
    }

    private static function asDay(mixed $value): ?int
    {
        if (!is_string($value) || !preg_match('/^\d{1,2}$/', $value)) {
            return null;
        }
        $n = (int) $value;

        return $n >= 1 && $n <= 31 ? $n : null;
    }
}
