<?php

namespace App\Repository;

use App\Entity\StatsSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StatsSnapshot>
 */
class StatsSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StatsSnapshot::class);
    }

    public function findOneByPeriod(string $period): ?StatsSnapshot
    {
        return $this->findOneBy(['period' => $period]);
    }
}
