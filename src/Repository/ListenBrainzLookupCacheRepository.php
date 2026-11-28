<?php

namespace App\Repository;

use App\Entity\ListenBrainzLookupCacheEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ListenBrainzLookupCacheEntry>
 */
class ListenBrainzLookupCacheRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ListenBrainzLookupCacheEntry::class);
    }

    /**
     * Fresh cached answer for the key: `['mbid' => ?string]`, or null when
     * absent or older than the TTL (then the caller looks it up again).
     *
     * @return array{mbid: ?string}|null
     */
    public function findFresh(string $key, int $ttlDays): ?array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'SELECT recording_mbid FROM listenbrainz_lookup_cache WHERE lookup_key = :k AND checked_at >= :since',
            [
                'k' => $key,
                'since' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                    ->modify(sprintf('-%d days', max(0, $ttlDays)))->format('Y-m-d H:i:s'),
            ],
        );
        if ($row === false) {
            return null;
        }

        return ['mbid' => $row['recording_mbid'] !== null && $row['recording_mbid'] !== '' ? (string) $row['recording_mbid'] : null];
    }

    public function save(string $key, ?string $recordingMbid): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO listenbrainz_lookup_cache (lookup_key, recording_mbid, checked_at) VALUES (:k, :m, :at)
             ON CONFLICT(lookup_key) DO UPDATE SET recording_mbid = excluded.recording_mbid, checked_at = excluded.checked_at',
            [
                'k' => $key,
                'm' => $recordingMbid,
                'at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            ],
        );
    }
}
