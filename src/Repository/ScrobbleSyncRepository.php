<?php

namespace App\Repository;

use App\Entity\Scrobble;
use App\Entity\ScrobbleSync;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ScrobbleSync> */
class ScrobbleSyncRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly EntityManagerInterface $em)
    {
        parent::__construct($registry, ScrobbleSync::class);
    }

    /**
     * Create pending ScrobbleSync rows for every scrobble that doesn't have
     * one yet for $target. Returns the number of rows created.
     *
     * Uses DBAL INSERT OR IGNORE for performance on large libraries.
     */
    public function prepareForTarget(string $target): int
    {
        return (int) $this->em->getConnection()->executeStatement(
            'INSERT OR IGNORE INTO scrobble_sync (scrobble_id, target, status)
             SELECT s.id, :target, :status
             FROM scrobbles s
             WHERE NOT EXISTS (
                 SELECT 1 FROM scrobble_sync ss
                 WHERE ss.scrobble_id = s.id AND ss.target = :target
             )',
            ['target' => $target, 'status' => ScrobbleSync::STATUS_PENDING],
        );
    }

    /**
     * Stream pending rows for $target, oldest scrobble first.
     *
     * @return iterable<ScrobbleSync>
     */
    public function streamPending(string $target, int $limit = 0): iterable
    {
        $qb = $this->createQueryBuilder('ss')
            ->join('ss.scrobble', 's')
            ->where('ss.target = :target')
            ->andWhere('ss.status = :status')
            ->setParameter('target', $target)
            ->setParameter('status', ScrobbleSync::STATUS_PENDING)
            ->orderBy('s.playedAt', 'ASC');

        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->toIterable();
    }

    public function countByTargetStatus(string $target, string $status): int
    {
        return (int) $this->createQueryBuilder('ss')
            ->select('COUNT(ss.id)')
            ->where('ss.target = :target')
            ->andWhere('ss.status = :status')
            ->setParameter('target', $target)
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Pending = scrobbles awaiting a first sync attempt for $target. This
     * covers both rows already marked `pending` in scrobble_sync AND
     * scrobbles that don't have a scrobble_sync row yet for this target
     * (rows are created lazily by {@see self::prepareForTarget()} on the
     * first sync run, so the counter must include un-prepared scrobbles
     * — otherwise it reads 0 right after a fetch and the user thinks
     * nothing needs to be done).
     */
    public function countPendingForTarget(string $target): int
    {
        return self::queryPendingCount($this->em->getConnection(), $target);
    }

    /**
     * Pure-SQL counterpart of {@see self::countPendingForTarget()} — exposed
     * statically so tests can hit a bare DBAL connection without spinning
     * up Doctrine's ManagerRegistry just to instantiate the repository.
     */
    public static function queryPendingCount(Connection $conn, string $target): int
    {
        return (int) $conn->fetchOne(
            'SELECT COUNT(s.id)
             FROM scrobbles s
             LEFT JOIN scrobble_sync ss
                 ON ss.scrobble_id = s.id AND ss.target = :target
             WHERE ss.id IS NULL OR ss.status = :pending',
            ['target' => $target, 'pending' => ScrobbleSync::STATUS_PENDING],
        );
    }

    public function countUnmatchedForTarget(string $target, ?string $period = null): int
    {
        [$expr] = self::periodClause($period);
        if ($expr === null) {
            return $this->countByTargetStatus($target, ScrobbleSync::STATUS_UNMATCHED);
        }

        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*)
             FROM scrobble_sync ss
             JOIN scrobbles s ON s.id = ss.scrobble_id
             WHERE ss.target = :target AND ss.status = :status AND ' . $expr,
            ['target' => $target, 'status' => ScrobbleSync::STATUS_UNMATCHED, 'period' => $period],
        );
    }

    /**
     * SQL fragment for filtering scrobbles by a period string — `YYYY` (year)
     * or `YYYY-MM` (month). `played_at` is a SQLite DATETIME string in the
     * tools DB, so `strftime` works directly (no 'unixepoch'). Returns [null]
     * when the period is empty/malformed.
     *
     * @return array{0: ?string}
     */
    private static function periodClause(?string $period): array
    {
        if ($period === null || $period === '') {
            return [null];
        }
        if (preg_match('/^\d{4}-\d{2}$/', $period) === 1) {
            return ["strftime('%Y-%m', s.played_at) = :period"];
        }
        if (preg_match('/^\d{4}$/', $period) === 1) {
            return ["strftime('%Y', s.played_at) = :period"];
        }

        return [null];
    }

    /**
     * Artists with the most UNMATCHED scrobbles for $target — « where to focus
     * the matching effort ». Optionally scoped to a period (YYYY / YYYY-MM).
     *
     * @return list<array{artist: string, count: int}>
     */
    public function topUnmatchedArtists(string $target, int $limit = 15, ?string $period = null): array
    {
        $where = ['ss.target = :target', 'ss.status = :status', 's.artist IS NOT NULL', "s.artist != ''"];
        $params = ['target' => $target, 'status' => ScrobbleSync::STATUS_UNMATCHED, 'lim' => $limit];
        [$expr] = self::periodClause($period);
        if ($expr !== null) {
            $where[] = $expr;
            $params['period'] = $period;
        }

        /** @var list<array{artist: string, count: int}> */
        return $this->em->getConnection()->fetchAllAssociative(
            'SELECT s.artist AS artist, COUNT(*) AS count
             FROM scrobble_sync ss
             JOIN scrobbles s ON s.id = ss.scrobble_id
             WHERE ' . implode(' AND ', $where) . '
             GROUP BY s.artist
             ORDER BY count DESC, s.artist ASC
             LIMIT :lim',
            $params,
            ['lim' => \Doctrine\DBAL\ParameterType::INTEGER],
        );
    }

    /**
     * Albums with the most UNMATCHED scrobbles for $target. The album's artist
     * uses `album_artist` when set, else the track artist.
     *
     * @return list<array{album: string, artist: string, count: int}>
     */
    public function topUnmatchedAlbums(string $target, int $limit = 15, ?string $period = null): array
    {
        $where = ['ss.target = :target', 'ss.status = :status', 's.album IS NOT NULL', "s.album != ''"];
        $params = ['target' => $target, 'status' => ScrobbleSync::STATUS_UNMATCHED, 'lim' => $limit];
        [$expr] = self::periodClause($period);
        if ($expr !== null) {
            $where[] = $expr;
            $params['period'] = $period;
        }

        /** @var list<array{album: string, artist: string, count: int}> */
        return $this->em->getConnection()->fetchAllAssociative(
            "SELECT s.album AS album,
                    COALESCE(NULLIF(s.album_artist, ''), s.artist) AS artist,
                    COUNT(*) AS count
             FROM scrobble_sync ss
             JOIN scrobbles s ON s.id = ss.scrobble_id
             WHERE " . implode(' AND ', $where) . '
             GROUP BY album, artist
             ORDER BY count DESC, album ASC
             LIMIT :lim',
            $params,
            ['lim' => \Doctrine\DBAL\ParameterType::INTEGER],
        );
    }

    /**
     * Reset unmatched rows for $target back to pending (for rematch).
     */
    public function resetUnmatchedToPending(string $target): int
    {
        return (int) $this->em->getConnection()->executeStatement(
            'UPDATE scrobble_sync SET status = :pending, attempted_at = NULL, synced_at = NULL, run_id = NULL
             WHERE target = :target AND status = :unmatched',
            [
                'pending' => ScrobbleSync::STATUS_PENDING,
                'target' => $target,
                'unmatched' => ScrobbleSync::STATUS_UNMATCHED,
            ],
        );
    }

    /**
     * Reset the unmatched rows of a single (artist, title) couple back to
     * pending — used by the per-track « retry match » button on
     * /navidrome/unmatched. Exact match on the raw scrobble artist/title
     * (as displayed in the aggregated list). Returns the number of rows
     * re-queued.
     */
    public function resetCoupleToPending(string $target, string $artist, string $title): int
    {
        return (int) $this->em->getConnection()->executeStatement(
            'UPDATE scrobble_sync SET status = :pending, attempted_at = NULL, synced_at = NULL, run_id = NULL
             WHERE target = :target AND status = :unmatched
               AND scrobble_id IN (SELECT id FROM scrobbles WHERE artist = :artist AND title = :title)',
            [
                'pending' => ScrobbleSync::STATUS_PENDING,
                'target' => $target,
                'unmatched' => ScrobbleSync::STATUS_UNMATCHED,
                'artist' => $artist,
                'title' => $title,
            ],
        );
    }

    /**
     * Reset every non-pending row for $target back to pending. Wipes
     * target_id and strategy too so the next sync starts from a clean
     * slate (they will be re-resolved by the matching cascade anyway).
     */
    public function resetAllToPending(string $target): int
    {
        return (int) $this->em->getConnection()->executeStatement(
            'UPDATE scrobble_sync
                SET status = :pending,
                    target_id = NULL,
                    strategy = NULL,
                    attempted_at = NULL,
                    synced_at = NULL,
                    run_id = NULL
              WHERE target = :target AND status <> :pending',
            [
                'pending' => ScrobbleSync::STATUS_PENDING,
                'target' => $target,
            ],
        );
    }

    /**
     * Distinct (artist, mbid_artist) of unmatched scrobbles carrying a
     * MusicBrainz artist id, ordered by play volume. Feeds the MBID-based
     * artist-alias generator ({@see \App\Service\AliasGenerator}).
     *
     * @return list<array{artist: string, mbid_artist: string, plays: int}>
     */
    public function unmatchedArtistMbids(string $target): array
    {
        /** @var list<array{artist: string, mbid_artist: string, plays: int}> */
        return $this->em->getConnection()->fetchAllAssociative(
            "SELECT s.artist AS artist, s.mbid_artist AS mbid_artist, COUNT(*) AS plays
             FROM scrobble_sync ss
             JOIN scrobbles s ON s.id = ss.scrobble_id
             WHERE ss.target = :target AND ss.status = :status
               AND s.mbid_artist IS NOT NULL AND s.mbid_artist != ''
             GROUP BY s.artist, s.mbid_artist
             ORDER BY plays DESC",
            ['target' => $target, 'status' => ScrobbleSync::STATUS_UNMATCHED],
        );
    }

    /**
     * Distinct unmatched (artist, title) couples with the set of MusicBrainz
     * album ids seen for them (comma-joined; UUIDs never contain commas) and
     * their play volume. Feeds the track-alias generator
     * ({@see \App\Service\AliasGenerator}).
     *
     * @return list<array{artist: string, title: string, mbid_albums: ?string, plays: int}>
     */
    public function unmatchedCouples(string $target): array
    {
        /** @var list<array{artist: string, title: string, mbid_albums: ?string, plays: int}> */
        return $this->em->getConnection()->fetchAllAssociative(
            "SELECT s.artist AS artist, s.title AS title,
                    GROUP_CONCAT(DISTINCT NULLIF(s.mbid_album, '')) AS mbid_albums,
                    COUNT(*) AS plays
             FROM scrobble_sync ss
             JOIN scrobbles s ON s.id = ss.scrobble_id
             WHERE ss.target = :target AND ss.status = :status
             GROUP BY s.artist, s.title
             ORDER BY plays DESC",
            ['target' => $target, 'status' => ScrobbleSync::STATUS_UNMATCHED],
        );
    }

    /**
     * Aggregate unmatched rows grouped by (artist, title, album) for display.
     *
     * @return list<array{artist: string, title: string, album: string|null, count: int, last_played_at: string}>
     */
    public function aggregateUnmatched(
        string $target,
        int $limit = 50,
        int $offset = 0,
        ?string $filterArtist = null,
        ?string $filterTitle = null,
        ?string $period = null,
    ): array {
        $where = ['ss.target = :target', 'ss.status = :status'];
        $params = ['target' => $target, 'status' => ScrobbleSync::STATUS_UNMATCHED];

        if ($filterArtist !== null) {
            $where[] = 'LOWER(s.artist) LIKE LOWER(:artist)';
            $params['artist'] = '%' . $filterArtist . '%';
        }
        if ($filterTitle !== null) {
            $where[] = 'LOWER(s.title) LIKE LOWER(:title)';
            $params['title'] = '%' . $filterTitle . '%';
        }
        [$periodExpr] = self::periodClause($period);
        if ($periodExpr !== null) {
            $where[] = $periodExpr;
            $params['period'] = $period;
        }

        $params['lim'] = $limit;
        $params['off'] = $offset;

        /** @var list<array{artist: string, title: string, album: string|null, count: int, last_played_at: string}> */
        return $this->em->getConnection()->fetchAllAssociative(
            'SELECT s.artist, s.title, s.album, COUNT(*) AS count, MAX(s.played_at) AS last_played_at
             FROM scrobble_sync ss
             JOIN scrobbles s ON s.id = ss.scrobble_id
             WHERE ' . implode(' AND ', $where) . '
             GROUP BY s.artist, s.title, s.album
             ORDER BY count DESC, last_played_at DESC
             LIMIT :lim OFFSET :off',
            $params,
        );
    }

    /**
     * Distinct unmatched artists for the given target with their lifetime
     * unmatched play count, ordered by plays. Feeds the MusicBrainz online
     * alias suggester ({@see \App\Service\MusicBrainzAliasSuggester}) which —
     * unlike the MBID-based generator — needs to query even artists that have
     * no `mbid_artist` on their scrobbles.
     *
     * @return list<array{artist: string, plays: int}>
     */
    public function unmatchedArtistsWithPlays(string $target): array
    {
        /** @var list<array{artist: string, plays: int}> */
        return $this->em->getConnection()->fetchAllAssociative(
            "SELECT s.artist AS artist, COUNT(*) AS plays
             FROM scrobble_sync ss
             JOIN scrobbles s ON s.id = ss.scrobble_id
             WHERE ss.target = :target AND ss.status = :status
               AND s.artist IS NOT NULL AND s.artist != ''
             GROUP BY s.artist
             ORDER BY plays DESC",
            ['target' => $target, 'status' => ScrobbleSync::STATUS_UNMATCHED],
        );
    }
}
