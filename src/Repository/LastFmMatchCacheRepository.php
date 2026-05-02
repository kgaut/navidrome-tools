<?php

namespace App\Repository;

use App\Entity\LastFmMatchCacheEntry;
use App\Navidrome\NavidromeRepository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LastFmMatchCacheEntry>
 */
class LastFmMatchCacheRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LastFmMatchCacheEntry::class);
    }

    /**
     * Look up a memoized resolution by normalized `(artist, title)`.
     */
    public function findByCouple(string $artist, string $title): ?LastFmMatchCacheEntry
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
     * Upsert a positive resolution. Only `persist()`s — caller flushes
     * (per-scrobble flush would tank import performance on large
     * histories).
     */
    public function recordPositive(
        string $artist,
        string $title,
        string $mediaFileId,
        string $strategy,
        ?int $confidenceScore = null,
    ): void {
        $this->upsert($artist, $title, $mediaFileId, $strategy, $confidenceScore);
    }

    /**
     * Upsert a negative resolution. Strategy is fixed to `negative` so we
     * can later distinguish « we tried and gave up » from a positive
     * match. Caller flushes.
     */
    public function recordNegative(string $artist, string $title): void
    {
        $this->upsert($artist, $title, null, LastFmMatchCacheEntry::STRATEGY_NEGATIVE, null);
    }

    private function upsert(
        string $artist,
        string $title,
        ?string $mediaFileId,
        string $strategy,
        ?int $confidenceScore,
    ): void {
        $existing = $this->findByCouple($artist, $title);
        if ($existing !== null) {
            $existing->setSource($artist, $title);
            $existing->setResolution($mediaFileId, $strategy, $confidenceScore);

            return;
        }
        $entry = new LastFmMatchCacheEntry($artist, $title, $mediaFileId, $strategy, $confidenceScore);
        $this->getEntityManager()->persist($entry);
    }

    /**
     * Drop the row matching this exact couple. Returns the number of
     * rows deleted (0 or 1). Called when the user creates / edits /
     * deletes a track-level alias for that couple.
     */
    public function purgeByCouple(string $artist, string $title): int
    {
        $artistNorm = NavidromeRepository::normalize($artist);
        $titleNorm = NavidromeRepository::normalize($title);
        if ($artistNorm === '' || $titleNorm === '') {
            return 0;
        }

        return (int) $this->createQueryBuilder('c')
            ->delete()
            ->where('c.sourceArtistNorm = :a')
            ->andWhere('c.sourceTitleNorm = :t')
            ->setParameter('a', $artistNorm)
            ->setParameter('t', $titleNorm)
            ->getQuery()
            ->execute();
    }

    /**
     * Drop every row whose source artist matches `$artist`. Called when
     * the user creates / edits an artist-level alias on `$artist` (the
     * source side of the alias) so the next cascade re-resolves through
     * the new alias.
     */
    public function purgeByArtist(string $artist): int
    {
        $artistNorm = NavidromeRepository::normalize($artist);
        if ($artistNorm === '') {
            return 0;
        }

        return (int) $this->createQueryBuilder('c')
            ->delete()
            ->where('c.sourceArtistNorm = :a')
            ->setParameter('a', $artistNorm)
            ->getQuery()
            ->execute();
    }

    /**
     * Drop every negative cache row older than `$ttlDays`. Called once
     * at the start of each import / rematch run. `$ttlDays <= 0` means
     * « never expire », so it's a no-op.
     */
    public function purgeStale(int $ttlDays): int
    {
        if ($ttlDays <= 0) {
            return 0;
        }
        $threshold = (new \DateTimeImmutable())->modify(sprintf('-%d days', $ttlDays));

        return (int) $this->createQueryBuilder('c')
            ->delete()
            ->where('c.targetMediaFileId IS NULL')
            ->andWhere('c.resolvedAt < :threshold')
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->execute();
    }

    /**
     * Wipe the table. `$negativeOnly = true` keeps positive matches —
     * useful when one wants to retry the Last.fm `track.getInfo` step
     * for previously-missed scrobbles without losing known good
     * resolutions.
     */
    public function purgeAll(bool $negativeOnly = false): int
    {
        $qb = $this->createQueryBuilder('c')->delete();
        if ($negativeOnly) {
            $qb->where('c.targetMediaFileId IS NULL');
        }

        return (int) $qb->getQuery()->execute();
    }
}
