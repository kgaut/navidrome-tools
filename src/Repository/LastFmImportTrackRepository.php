<?php

namespace App\Repository;

use App\Entity\LastFmImportTrack;
use App\Entity\RunHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LastFmImportTrack>
 */
class LastFmImportTrackRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LastFmImportTrack::class);
    }

    /**
     * Tracks for a given run, optionally filtered by status and free-text
     * search on artist/title. Capped at $limit rows (caller passes a sane
     * default, the page exposes a "view more" hint when truncated).
     *
     * @return LastFmImportTrack[]
     */
    public function findForRun(RunHistory $run, ?string $status = null, ?string $q = null, int $limit = 500): array
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.runHistory = :run')
            ->setParameter('run', $run)
            ->orderBy('t.playedAt', 'DESC')
            ->setMaxResults($limit);
        if ($status !== null && $status !== '') {
            $qb->andWhere('t.status = :s')->setParameter('s', $status);
        }
        if ($q !== null && $q !== '') {
            $qb->andWhere('t.artist LIKE :q OR t.title LIKE :q')->setParameter('q', '%' . $q . '%');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return array<string, int> status → count
     */
    public function countByStatusForRun(RunHistory $run): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.status AS status, COUNT(t.id) AS c')
            ->andWhere('t.runHistory = :run')
            ->setParameter('run', $run)
            ->groupBy('t.status')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['status']] = (int) $r['c'];
        }

        return $out;
    }
}
