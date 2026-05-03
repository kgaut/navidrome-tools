<?php

namespace App\Repository;

use App\Entity\LastFmHistoryEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LastFmHistoryEntry>
 */
class LastFmHistoryEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LastFmHistoryEntry::class);
    }

    /**
     * Most recent cached scrobbles for the given Last.fm user.
     *
     * @return LastFmHistoryEntry[]
     */
    public function findRecentForUser(string $lastfmUser, int $limit = 100): array
    {
        return $this->createQueryBuilder('h')
            ->andWhere('h.lastfmUser = :u')
            ->setParameter('u', $lastfmUser)
            ->orderBy('h.playedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Last fetch timestamp for the user (the most recent fetched_at).
     */
    public function findLastFetchedAt(string $lastfmUser): ?\DateTimeImmutable
    {
        $row = $this->createQueryBuilder('h')
            ->select('MAX(h.fetchedAt) AS last_fetched')
            ->andWhere('h.lastfmUser = :u')
            ->setParameter('u', $lastfmUser)
            ->getQuery()
            ->getOneOrNullResult();

        if (!is_array($row) || empty($row['last_fetched'])) {
            return null;
        }

        // fetchedAt is stored in UTC (see UtcDateTimeImmutableType); MAX() returns
        // the raw wall-clock string, so we must tag it UTC explicitly — otherwise
        // PHP's default timezone re-interprets it and shifts the instant.
        return new \DateTimeImmutable($row['last_fetched'], new \DateTimeZone('UTC'));
    }

    public function deleteForUser(string $lastfmUser): int
    {
        return (int) $this->createQueryBuilder('h')
            ->delete()
            ->andWhere('h.lastfmUser = :u')
            ->setParameter('u', $lastfmUser)
            ->getQuery()
            ->execute();
    }
}
