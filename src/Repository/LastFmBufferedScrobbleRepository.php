<?php

namespace App\Repository;

use App\Entity\LastFmBufferedScrobble;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LastFmBufferedScrobble>
 */
class LastFmBufferedScrobbleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LastFmBufferedScrobble::class);
    }

    /**
     * Total rows not yet synced to Navidrome (pending processing).
     */
    public function countUnsyncedNavidrome(): int
    {
        return (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.syncedNavidrome = FALSE')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Total rows not yet synced to Strawberry (all unsynced: pending + unmatched).
     */
    public function countUnsyncedStrawberry(): int
    {
        return (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.syncedStrawberry = FALSE')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Rows never yet attempted for Strawberry (strawberry_attempted_at IS NULL).
     */
    public function countPendingStrawberry(): int
    {
        return (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.syncedStrawberry = FALSE')
            ->andWhere('b.strawberryAttemptedAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Rows attempted for Strawberry but not matched (synced=false, attempted_at set).
     */
    public function countUnmatchedStrawberry(): int
    {
        return (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->where('b.syncedStrawberry = FALSE')
            ->andWhere('b.strawberryAttemptedAt IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Total rows in the buffer (all time, regardless of sync status).
     */
    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Iterate over buffered scrobbles not yet synced to Navidrome, in
     * played_at ASC order — oldest first so a partial run (--limit) drains
     * the oldest tail naturally and a subsequent run picks up where this one
     * stopped.
     *
     * @return iterable<LastFmBufferedScrobble>
     */
    public function streamAll(int $limit = 0): iterable
    {
        $qb = $this->createQueryBuilder('b')
            ->where('b.syncedNavidrome = FALSE')
            ->orderBy('b.playedAt', 'ASC');
        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->toIterable();
    }

    /**
     * Aggregate unmatched Strawberry rows grouped by (artist, title, album),
     * ordered by scrobble count DESC then last played_at DESC.
     *
     * @return list<array{artist: string, title: string, album: string|null, scrobble_count: int, last_played_at: string}>
     */
    public function queryUnmatchedStrawberryAggregated(
        ?string $artist = null,
        ?string $title = null,
        int $offset = 0,
        int $limit = 50,
    ): array {
        $conn = $this->getEntityManager()->getConnection();
        $where = [
            'synced_strawberry = 0',
            'strawberry_attempted_at IS NOT NULL',
        ];
        $params = [];

        if ($artist !== null) {
            $where[] = "LOWER(artist) LIKE LOWER(:artist)";
            $params['artist'] = '%' . $artist . '%';
        }
        if ($title !== null) {
            $where[] = "LOWER(title) LIKE LOWER(:title)";
            $params['title'] = '%' . $title . '%';
        }

        $sql = 'SELECT artist, title, album, COUNT(*) AS scrobble_count, MAX(played_at) AS last_played_at '
            . 'FROM lastfm_import_buffer '
            . 'WHERE ' . implode(' AND ', $where) . ' '
            . 'GROUP BY artist, title, album '
            . 'ORDER BY scrobble_count DESC, last_played_at DESC '
            . 'LIMIT :lim OFFSET :off';

        $params['lim'] = $limit;
        $params['off'] = $offset;

        /** @var list<array{artist: string, title: string, album: string|null, scrobble_count: int, last_played_at: string}> */
        return $conn->fetchAllAssociative($sql, $params);
    }

    /**
     * Iterate over buffered scrobbles not yet synced to Strawberry, in
     * played_at ASC order.
     *
     * By default only streams rows never yet attempted (strawberry_attempted_at
     * IS NULL). Pass $includeUnmatched = true to also retry previously
     * unmatched rows (e.g. after adding songs to the Strawberry library).
     *
     * @return iterable<LastFmBufferedScrobble>
     */
    public function streamUnsyncedStrawberry(int $limit = 0, bool $includeUnmatched = false): iterable
    {
        $qb = $this->createQueryBuilder('b')
            ->where('b.syncedStrawberry = FALSE')
            ->orderBy('b.playedAt', 'ASC');

        if (!$includeUnmatched) {
            $qb->andWhere('b.strawberryAttemptedAt IS NULL');
        }

        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->toIterable();
    }
}
