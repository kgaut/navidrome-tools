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
     * Total rows currently waiting in the buffer.
     */
    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Iterate over buffered scrobbles in played_at ASC order — oldest first
     * so a partial run (--limit) drains the tail naturally and a subsequent
     * run picks up where this one stopped.
     *
     * @return iterable<LastFmBufferedScrobble>
     */
    public function streamAll(int $limit = 0): iterable
    {
        $qb = $this->createQueryBuilder('b')->orderBy('b.playedAt', 'ASC');
        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->toIterable();
    }
}
