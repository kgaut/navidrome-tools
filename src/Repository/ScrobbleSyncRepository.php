<?php

namespace App\Repository;

use App\Entity\Scrobble;
use App\Entity\ScrobbleSync;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<ScrobbleSync> */
class ScrobbleSyncRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly EntityManagerInterface $em)
    {
        parent::__construct($registry, ScrobbleSync::class);
    }

    /**
     * Create pending ScrobbleSync rows for every scrobble that doesn't have
     * one yet for $target. Returns the number of rows created.
     *
     * Uses DBAL INSERT OR IGNORE for performance on large libraries.
     */
    public function prepareForTarget(string $target): int
    {
        return (int) $this->em->getConnection()->executeStatement(
            'INSERT OR IGNORE INTO scrobble_sync (scrobble_id, target, status)
             SELECT s.id, :target, :status
             FROM scrobbles s
             WHERE NOT EXISTS (
                 SELECT 1 FROM scrobble_sync ss
                 WHERE ss.scrobble_id = s.id AND ss.target = :target
             )',
            ['target' => $target, 'status' => ScrobbleSync::STATUS_PENDING],
        );
    }

    /**
     * Stream pending rows for $target, oldest scrobble first.
     *
     * @return iterable<ScrobbleSync>
     */
    public function streamPending(string $target, int $limit = 0): iterable
    {
        $qb = $this->createQueryBuilder('ss')
            ->join('ss.scrobble', 's')
            ->where('ss.target = :target')
            ->andWhere('ss.status = :status')
            ->setParameter('target', $target)
            ->setParameter('status', ScrobbleSync::STATUS_PENDING)
            ->orderBy('s.playedAt', 'ASC');

        if ($limit > 0) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->toIterable();
    }

    public function countByTargetStatus(string $target, string $status): int
    {
        return (int) $this->createQueryBuilder('ss')
            ->select('COUNT(ss.id)')
            ->where('ss.target = :target')
            ->andWhere('ss.status = :status')
            ->setParameter('target', $target)
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countPendingForTarget(string $target): int
    {
        return $this->countByTargetStatus($target, ScrobbleSync::STATUS_PENDING);
    }

    public function countUnmatchedForTarget(string $target): int
    {
        return $this->countByTargetStatus($target, ScrobbleSync::STATUS_UNMATCHED);
    }

    /**
     * Reset unmatched rows for $target back to pending (for rematch).
     */
    public function resetUnmatchedToPending(string $target): int
    {
        return (int) $this->em->getConnection()->executeStatement(
            'UPDATE scrobble_sync SET status = :pending, attempted_at = NULL, synced_at = NULL, run_id = NULL
             WHERE target = :target AND status = :unmatched',
            [
                'pending' => ScrobbleSync::STATUS_PENDING,
                'target' => $target,
                'unmatched' => ScrobbleSync::STATUS_UNMATCHED,
            ],
        );
    }

    /**
     * Aggregate unmatched rows grouped by (artist, title, album) for display.
     *
     * @return list<array{artist: string, title: string, album: string|null, count: int, last_played_at: string}>
     */
    public function aggregateUnmatched(
        string $target,
        int $limit = 50,
        int $offset = 0,
        ?string $filterArtist = null,
        ?string $filterTitle = null,
    ): array {
        $where = ['ss.target = :target', 'ss.status = :status'];
        $params = ['target' => $target, 'status' => ScrobbleSync::STATUS_UNMATCHED];

        if ($filterArtist !== null) {
            $where[] = 'LOWER(s.artist) LIKE LOWER(:artist)';
            $params['artist'] = '%' . $filterArtist . '%';
        }
        if ($filterTitle !== null) {
            $where[] = 'LOWER(s.title) LIKE LOWER(:title)';
            $params['title'] = '%' . $filterTitle . '%';
        }

        $params['lim'] = $limit;
        $params['off'] = $offset;

        /** @var list<array{artist: string, title: string, album: string|null, count: int, last_played_at: string}> */
        return $this->em->getConnection()->fetchAllAssociative(
            'SELECT s.artist, s.title, s.album, COUNT(*) AS count, MAX(s.played_at) AS last_played_at
             FROM scrobble_sync ss
             JOIN scrobbles s ON s.id = ss.scrobble_id
             WHERE ' . implode(' AND ', $where) . '
             GROUP BY s.artist, s.title, s.album
             ORDER BY count DESC, last_played_at DESC
             LIMIT :lim OFFSET :off',
            $params,
        );
    }
}
