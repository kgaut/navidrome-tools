<?php

namespace App\Repository;

use App\Entity\LastFmArtistAlias;
use App\Navidrome\NavidromeRepository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LastFmArtistAlias>
 */
class LastFmArtistAliasRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LastFmArtistAlias::class);
    }

    /**
     * Look up an artist alias by its source name. Lookup is normalized
     * via {@see NavidromeRepository::normalize()} (case / accents /
     * punctuation insensitive).
     */
    public function findBySourceArtist(string $artist): ?LastFmArtistAlias
    {
        $norm = NavidromeRepository::normalize($artist);
        if ($norm === '') {
            return null;
        }

        return $this->findOneBy(['sourceArtistNorm' => $norm]);
    }

    /**
     * Resolve `$artist` to its canonical form via the alias table, or
     * return null when no alias is registered. Used by the matching
     * cascade ({@see \App\LastFm\ScrobbleMatcher}) to rewrite the source
     * artist before any heuristic.
     */
    public function resolve(string $artist): ?string
    {
        $alias = $this->findBySourceArtist($artist);

        return $alias?->getTargetArtist();
    }

    /**
     * Paginated search by substring on source / target artist.
     *
     * @return list<LastFmArtistAlias>
     */
    public function search(string $query, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('a')
            ->orderBy('a.createdAt', 'DESC')
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage);

        if ($query !== '') {
            $qb->andWhere('LOWER(a.sourceArtist) LIKE :q OR LOWER(a.targetArtist) LIKE :q')
                ->setParameter('q', '%' . mb_strtolower($query) . '%');
        }

        /** @var list<LastFmArtistAlias> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    public function countSearch(string $query): int
    {
        $qb = $this->createQueryBuilder('a')->select('COUNT(a.id)');
        if ($query !== '') {
            $qb->andWhere('LOWER(a.sourceArtist) LIKE :q OR LOWER(a.targetArtist) LIKE :q')
                ->setParameter('q', '%' . mb_strtolower($query) . '%');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
