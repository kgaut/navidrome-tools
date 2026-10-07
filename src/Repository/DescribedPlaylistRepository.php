<?php

namespace App\Repository;

use App\Entity\DescribedPlaylist;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DescribedPlaylist>
 */
class DescribedPlaylistRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DescribedPlaylist::class);
    }

    /**
     * @return list<DescribedPlaylist> by name
     */
    public function findAllOrdered(): array
    {
        /** @var list<DescribedPlaylist> $rows */
        $rows = $this->findBy([], ['name' => 'ASC']);

        return $rows;
    }
}
