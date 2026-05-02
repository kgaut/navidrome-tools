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
     * Iterate over unmatched tracks across all runs (or a single run when
     * $runId is set). Returns a generator so callers can stream rows
     * without buffering the whole table — useful for the rematch CLI which
     * may iterate over tens of thousands of historical unmatched entries.
     *
     * @param int|null $runId Filter to a single run when non-null
     * @param int      $limit Hard cap (set high for full sweeps; the cli
     *                        passes its --limit flag through). 0 means no
     *                        limit.
     *
     * @return iterable<LastFmImportTrack>
     */
    public function streamUnmatched(?int $runId = null, int $limit = 0): iterable
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.status = :s')
            ->setParameter('s', LastFmImportTrack::STATUS_UNMATCHED)
            ->orderBy('t.id', 'ASC');
        if ($runId !== null) {
            $qb->andWhere('IDENTITY(t.runHistory) = :run')->setParameter('run', $runId);
        }
        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->toIterable();
    }

    /**
     * Total unmatched tracks (optionally for a specific run).
     */
    public function countUnmatched(?int $runId = null): int
    {
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->andWhere('t.status = :s')
            ->setParameter('s', LastFmImportTrack::STATUS_UNMATCHED);
        if ($runId !== null) {
            $qb->andWhere('IDENTITY(t.runHistory) = :run')->setParameter('run', $runId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
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
