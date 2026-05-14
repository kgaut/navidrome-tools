<?php

namespace App\Repository;

use App\Entity\LastFmAlias;
use App\Navidrome\NavidromeRepository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LastFmAlias>
 */
class LastFmAliasRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LastFmAlias::class);
    }

    /**
     * Look up an alias for a Last.fm scrobble. Both inputs are normalized
     * with {@see NavidromeRepository::normalize()} so the lookup is
     * accent/case/punctuation-insensitive.
     */
    public function findByScrobble(string $artist, string $title): ?LastFmAlias
    {
        $artistNorm = NavidromeRepository::normalize($artist);
        $titleNorm = NavidromeRepository::normalize($title);
        if ($artistNorm === '' || $titleNorm === '') {
            return null;
        }

        return $this->findOneBy([
            'sourceArtistNorm' => $artistNorm,
            'sourceTitleNorm' => $titleNorm,
        ]);
    }

    /**
     * Paginated search by raw substring on either source field. Used by
     * the alias admin page.
     *
     * @return list<LastFmAlias>
     */
    public function search(string $query, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('a')
            ->orderBy('a.createdAt', 'DESC')
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage);

        if ($query !== '') {
            $qb->andWhere('LOWER(a.sourceArtist) LIKE :q OR LOWER(a.sourceTitle) LIKE :q')
                ->setParameter('q', '%' . mb_strtolower($query) . '%');
        }

        /** @var list<LastFmAlias> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
    }

    public function countSearch(string $query): int
    {
        $qb = $this->createQueryBuilder('a')->select('COUNT(a.id)');
        if ($query !== '') {
            $qb->andWhere('LOWER(a.sourceArtist) LIKE :q OR LOWER(a.sourceTitle) LIKE :q')
                ->setParameter('q', '%' . mb_strtolower($query) . '%');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
