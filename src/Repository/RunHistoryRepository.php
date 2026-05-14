<?php

namespace App\Repository;

use App\Entity\RunHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<RunHistory> */
class RunHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RunHistory::class);
    }

    /**
     * @param array{type?: ?string, status?: ?string, q?: ?string} $filters
     * @return array{items: RunHistory[], total: int}
     */
    public function findFilteredPaginated(array $filters, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('h')->orderBy('h.startedAt', 'DESC');

        if (!empty($filters['type'])) {
            $qb->andWhere('h.type = :type')->setParameter('type', $filters['type']);
        }
        if (!empty($filters['status'])) {
            $qb->andWhere('h.status = :status')->setParameter('status', $filters['status']);
        }
        if (!empty($filters['q'])) {
            $qb->andWhere('LOWER(h.label) LIKE :q')->setParameter('q', '%' . strtolower($filters['q']) . '%');
        }

        $total = (int) (clone $qb)->select('COUNT(h.id)')->getQuery()->getSingleScalarResult();

        /** @var RunHistory[] $items */
        $items = $qb->setMaxResults($perPage)->setFirstResult(($page - 1) * $perPage)->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }

    public function purgeOlderThan(\DateTimeInterface $cutoff): int
    {
        return (int) $this->createQueryBuilder('h')
            ->delete()
            ->andWhere('h.startedAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }
}
