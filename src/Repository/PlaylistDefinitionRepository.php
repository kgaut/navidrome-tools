<?php

namespace App\Repository;

use App\Entity\PlaylistDefinition;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlaylistDefinition>
 */
class PlaylistDefinitionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlaylistDefinition::class);
    }

    /**
     * @return PlaylistDefinition[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('p')
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array{q?: ?string, enabled?: ?bool, sort?: ?string} $filters
     *
     * @return PlaylistDefinition[]
     */
    public function findFiltered(array $filters): array
    {
        $qb = $this->createQueryBuilder('p');

        if (!empty($filters['q'])) {
            $qb->andWhere('LOWER(p.name) LIKE :q')->setParameter('q', '%' . strtolower($filters['q']) . '%');
        }

        if (isset($filters['enabled']) && is_bool($filters['enabled'])) {
            $qb->andWhere('p.enabled = :en')->setParameter('en', $filters['enabled']);
        }

        $sort = $filters['sort'] ?? 'name';
        switch ($sort) {
            case 'last_run':
                // Most recent first; null at the bottom (SQLite sorts NULLs first by default in DESC).
                $qb->addSelect('CASE WHEN p.lastRunAt IS NULL THEN 1 ELSE 0 END AS HIDDEN nulls_last')
                   ->orderBy('nulls_last', 'ASC')
                   ->addOrderBy('p.lastRunAt', 'DESC');
                break;
            case 'name':
            default:
                $qb->orderBy('p.name', 'ASC');
                break;
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Pick a unique name for a duplicated definition. Tries "<base> (copie)",
     * then "<base> (copie 2)", "<base> (copie 3)", … until it finds a slot.
     */
    public function buildDuplicateName(string $baseName): string
    {
        $candidate = $baseName . ' (copie)';
        if ($this->findOneByName($candidate) === null) {
            return $candidate;
        }
        for ($i = 2; $i < 1000; $i++) {
            $candidate = sprintf('%s (copie %d)', $baseName, $i);
            if ($this->findOneByName($candidate) === null) {
                return $candidate;
            }
        }

        // 1000 copies of the same definition feels exotic; fall back on a timestamp.
        return $baseName . ' (copie ' . date('Y-m-d His') . ')';
    }

    public function findOneByName(string $name): ?PlaylistDefinition
    {
        return $this->findOneBy(['name' => $name]);
    }
}
