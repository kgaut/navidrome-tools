<?php

namespace App\Repository;

use App\Entity\StatsHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StatsHistory>
 */
class StatsHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly EntityManagerInterface $em)
    {
        parent::__construct($registry, StatsHistory::class);
    }

    /**
     * Record (or refresh) the library counts for a given calendar day. The
     * `day` column is unique, so re-running on the same day updates the
     * existing row instead of creating a duplicate — « une mesure par jour ».
     *
     * @param array{tracks: int, artists: int, albums: int, duration_seconds: int} $counts
     */
    public function recordDay(\DateTimeImmutable $day, array $counts): void
    {
        $key = $day->format('Y-m-d');
        $row = $this->findOneBy(['day' => $key]);

        if ($row === null) {
            $row = new StatsHistory(
                $key,
                (int) $counts['tracks'],
                (int) $counts['artists'],
                (int) $counts['albums'],
                (int) $counts['duration_seconds'],
            );
            $this->em->persist($row);
        } else {
            $row->updateCounts(
                (int) $counts['tracks'],
                (int) $counts['artists'],
                (int) $counts['albums'],
                (int) $counts['duration_seconds'],
            );
        }

        $this->em->flush();
    }

    /**
     * The daily measurements, oldest first, capped to the last `$maxDays`
     * recorded days (for the evolution chart).
     *
     * @return list<array{day: string, tracks: int, artists: int, albums: int, duration_seconds: int}>
     */
    public function series(int $maxDays = 365): array
    {
        /** @var list<StatsHistory> $rows */
        $rows = $this->createQueryBuilder('h')
            ->orderBy('h.day', 'DESC')
            ->setMaxResults(max(1, $maxDays))
            ->getQuery()
            ->getResult();

        $rows = array_reverse($rows); // back to ascending (oldest first)

        return array_map(static fn (StatsHistory $h): array => [
            'day' => $h->getDay(),
            'tracks' => $h->getTracks(),
            'artists' => $h->getArtists(),
            'albums' => $h->getAlbums(),
            'duration_seconds' => $h->getDurationSeconds(),
        ], $rows);
    }
}
