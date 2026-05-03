<?php

namespace App\Repository;

use App\Entity\LastFmImportTrack;
use App\Entity\RunHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LastFmImportTrack>
 */
class LastFmImportTrackRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LastFmImportTrack::class);
    }

    /**
     * Tracks for a given run, optionally filtered by status and free-text
     * search on artist/title. Capped at $limit rows (caller passes a sane
     * default, the page exposes a "view more" hint when truncated).
     *
     * @return LastFmImportTrack[]
     */
    public function findForRun(RunHistory $run, ?string $status = null, ?string $q = null, int $limit = 500): array
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.runHistory = :run')
            ->setParameter('run', $run)
            ->orderBy('t.playedAt', 'DESC')
            ->setMaxResults($limit);
        if ($status !== null && $status !== '') {
            $qb->andWhere('t.status = :s')->setParameter('s', $status);
        }
        if ($q !== null && $q !== '') {
            $qb->andWhere('t.artist LIKE :q OR t.title LIKE :q')->setParameter('q', '%' . $q . '%');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Iterate over unmatched tracks across all runs (or a single run when
     * $runId is set). Returns a generator so callers can stream rows
     * without buffering the whole table — useful for the rematch CLI which
     * may iterate over tens of thousands of historical unmatched entries.
     *
     * When $random is true the candidates are shuffled in PHP before $limit
     * is applied (DQL has no portable RANDOM() and adding a custom function
     * for one feature is overkill). The memory cost is bounded by the
     * number of unmatched IDs (integers, negligible even at 100k rows).
     *
     * @param int|null $runId  Filter to a single run when non-null
     * @param int      $limit  Hard cap (set high for full sweeps; the cli
     *                         passes its --limit flag through). 0 means no
     *                         limit.
     * @param bool     $random Shuffle candidates before applying $limit.
     *                         Useful to sample a subset when debugging
     *                         matching heuristics on a large backlog.
     *
     * @return iterable<LastFmImportTrack>
     */
    public function streamUnmatched(?int $runId = null, int $limit = 0, bool $random = false): iterable
    {
        if ($random) {
            return $this->streamUnmatchedRandom($runId, $limit);
        }

        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.status = :s')
            ->setParameter('s', LastFmImportTrack::STATUS_UNMATCHED)
            ->orderBy('t.id', 'ASC');
        if ($runId !== null) {
            $qb->andWhere('IDENTITY(t.runHistory) = :run')->setParameter('run', $runId);
        }
        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->toIterable();
    }

    /**
     * @return \Generator<int, LastFmImportTrack>
     */
    private function streamUnmatchedRandom(?int $runId, int $limit): \Generator
    {
        $qb = $this->createQueryBuilder('t')
            ->select('t.id')
            ->andWhere('t.status = :s')
            ->setParameter('s', LastFmImportTrack::STATUS_UNMATCHED);
        if ($runId !== null) {
            $qb->andWhere('IDENTITY(t.runHistory) = :run')->setParameter('run', $runId);
        }

        /** @var list<array{id: int}> $rows */
        $rows = $qb->getQuery()->getArrayResult();
        $ids = array_column($rows, 'id');
        shuffle($ids);
        if ($limit > 0 && count($ids) > $limit) {
            $ids = array_slice($ids, 0, $limit);
        }

        foreach ($ids as $id) {
            $entity = $this->find($id);
            if ($entity !== null) {
                yield $entity;
            }
        }
    }

    /**
     * Total unmatched tracks (optionally for a specific run).
     */
    public function countUnmatched(?int $runId = null): int
    {
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.status = :s')
            ->setParameter('s', LastFmImportTrack::STATUS_UNMATCHED);
        if ($runId !== null) {
            $qb->andWhere('IDENTITY(t.runHistory) = :run')->setParameter('run', $runId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Aggregate unmatched tracks across all runs by (artist, title, album).
     * Each result row groups every scrobble that shares the same triplet,
     * with a count and the most recent played_at — that's the actionable
     * unit on /lastfm/unmatched (one alias creation = one (artist, title)
     * couple, not one per scrobble row).
     *
     * Filters use case-insensitive substring match. Pagination is 1-based.
     *
     * @return array{
     *     items: list<array{artist:string, title:string, album:?string, scrobbles:int, last_played:\DateTimeImmutable}>,
     *     total: int
     * }
     */
    public function findUnmatchedAggregated(
        ?string $artist = null,
        ?string $title = null,
        ?string $album = null,
        int $page = 1,
        int $perPage = 50,
    ): array {
        return self::queryUnmatchedAggregated(
            $this->getEntityManager()->getConnection(),
            $artist,
            $title,
            $album,
            $page,
            $perPage,
        );
    }

    /**
     * Static SQL helper exposed for testability — same contract as
     * {@see findUnmatchedAggregated()} but takes the Connection directly so
     * tests can wire a SQLite fixture without booting the Doctrine ORM.
     *
     * @return array{
     *     items: list<array{artist:string, title:string, album:?string, scrobbles:int, last_played:\DateTimeImmutable}>,
     *     total: int
     * }
     */
    public static function queryUnmatchedAggregated(
        Connection $conn,
        ?string $artist,
        ?string $title,
        ?string $album,
        int $page,
        int $perPage,
    ): array {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        $where = "status = 'unmatched'";
        $params = [];
        if ($artist !== null && $artist !== '') {
            $where .= ' AND LOWER(artist) LIKE :artist';
            $params['artist'] = '%' . mb_strtolower($artist) . '%';
        }
        if ($title !== null && $title !== '') {
            $where .= ' AND LOWER(title) LIKE :title';
            $params['title'] = '%' . mb_strtolower($title) . '%';
        }
        if ($album !== null && $album !== '') {
            $where .= " AND LOWER(COALESCE(album, '')) LIKE :album";
            $params['album'] = '%' . mb_strtolower($album) . '%';
        }

        $totalSql = 'SELECT COUNT(*) FROM ('
            . "SELECT 1 FROM lastfm_import_track WHERE $where "
            . 'GROUP BY artist, title, album'
            . ') sub';
        $total = (int) $conn->fetchOne($totalSql, $params);

        $offset = ($page - 1) * $perPage;
        $itemsSql = 'SELECT artist, title, album, COUNT(*) AS scrobbles, MAX(played_at) AS last_played '
            . "FROM lastfm_import_track WHERE $where "
            . 'GROUP BY artist, title, album '
            . 'ORDER BY scrobbles DESC, last_played DESC '
            . 'LIMIT :limit OFFSET :offset';
        $itemsParams = $params + ['limit' => $perPage, 'offset' => $offset];

        $items = [];
        foreach ($conn->fetchAllAssociative($itemsSql, $itemsParams) as $row) {
            $items[] = [
                'artist' => (string) $row['artist'],
                'title' => (string) $row['title'],
                'album' => ($row['album'] ?? null) !== null && $row['album'] !== '' ? (string) $row['album'] : null,
                'scrobbles' => (int) $row['scrobbles'],
                'last_played' => new \DateTimeImmutable((string) $row['last_played']),
            ];
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return array<string, int> status → count
     */
    public function countByStatusForRun(RunHistory $run): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.status AS status, COUNT(t.id) AS c')
            ->andWhere('t.runHistory = :run')
            ->setParameter('run', $run)
            ->groupBy('t.status')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['status']] = (int) $r['c'];
        }

        return $out;
    }
}
