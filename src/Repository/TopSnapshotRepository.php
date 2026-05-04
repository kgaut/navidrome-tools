<?php

namespace App\Repository;

use App\Entity\TopSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TopSnapshot>
 */
class TopSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TopSnapshot::class);
    }

    public function findOneByWindow(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $client = null): ?TopSnapshot
    {
        return $this->findOneBy([
            'windowFrom' => $from->getTimestamp(),
            'windowTo' => $to->getTimestamp(),
            'client' => ($client !== null && $client !== '') ? $client : null,
        ]);
    }

    /**
     * @return TopSnapshot[]
     */
    public function findRecent(int $limit = 10): array
    {
        return $this->createQueryBuilder('t')
            ->orderBy('t.computedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
