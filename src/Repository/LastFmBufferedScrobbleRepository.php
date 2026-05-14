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
     * Total rows not yet synced to Strawberry (pending Strawberry processing).
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
     * Iterate over buffered scrobbles not yet synced to Strawberry, in
     * played_at ASC order.
     *
     * @return iterable<LastFmBufferedScrobble>
     */
    public function streamUnsyncedStrawberry(int $limit = 0): iterable
    {
        $qb = $this->createQueryBuilder('b')
            ->where('b.syncedStrawberry = FALSE')
            ->orderBy('b.playedAt', 'ASC');
        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->toIterable();
    }
}
