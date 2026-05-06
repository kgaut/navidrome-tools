<?php

namespace App\Repository;

use App\Entity\LastFmMatchCacheEntry;
use App\Navidrome\NavidromeRepository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LastFmMatchCacheEntry>
 *
 * Reads and writes go through raw SQL on the underlying DBAL connection
 * rather than the ORM unit-of-work. The cache is just a memoization table
 * (no relations, no lifecycle), and the previous ORM-based `persist()` +
 * `findOneBy()` dance kept stumbling on the unique index
 * `uniq_lastfm_match_cache_source_norm` whenever Doctrine's identity-map /
 * pending-state diverged from the actual row state — typically when a
 * persisted-but-unflushed entity was forgotten and a duplicate persist for
 * the same normalized couple slipped through to flush. SQLite UPSERT
 * (`INSERT … ON CONFLICT … DO UPDATE`) handles dedup atomically at the DB
 * level, so the in-memory bookkeeping is no longer necessary.
 */
class LastFmMatchCacheRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LastFmMatchCacheEntry::class);
    }

    /**
     * Look up a memoized resolution by normalized `(artist, title)`. Returns a
     * detached `LastFmMatchCacheEntry` hydrated from the row — sufficient for
     * the matcher's getters (`getTargetMediaFileId`, `getStrategy`,
     * `isPositive`, `isStale`). The entity is intentionally unmanaged: any
     * subsequent `recordPositive/Negative` must go through `upsert()` (raw
     * SQL UPSERT) rather than mutating this object.
     */
    public function findByCouple(string $artist, string $title): ?LastFmMatchCacheEntry
    {
        $artistNorm = NavidromeRepository::normalize($artist);
        $titleNorm = NavidromeRepository::normalize($title);
        if ($artistNorm === '' || $titleNorm === '') {
            return null;
        }

        $row = $this->connection()->fetchAssociative(
            'SELECT source_artist, source_title, target_media_file_id, strategy, confidence_score, resolved_at
             FROM lastfm_match_cache
             WHERE source_artist_norm = :a AND source_title_norm = :t',
            ['a' => $artistNorm, 't' => $titleNorm],
        );
        if ($row === false) {
            return null;
        }

        return LastFmMatchCacheEntry::fromRow($row);
    }

    /**
     * Upsert a positive resolution. Atomic via SQLite UPSERT.
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
     * can later distinguish « we tried and gave up » from a positive match.
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
        $artistNorm = NavidromeRepository::normalize($artist);
        $titleNorm = NavidromeRepository::normalize($title);
        // Don't cache rows that would normalize to an empty key — they'd
        // collide on the unique (norm, norm) index after the first insert
        // and the cascade can't lookup them anyway.
        if ($artistNorm === '' || $titleNorm === '') {
            return;
        }

        $this->connection()->executeStatement(
            'INSERT INTO lastfm_match_cache
                (source_artist, source_title, source_artist_norm, source_title_norm,
                 target_media_file_id, strategy, confidence_score, resolved_at)
             VALUES (:sa, :st, :san, :stn, :tid, :strat, :conf, :rat)
             ON CONFLICT (source_artist_norm, source_title_norm) DO UPDATE SET
                source_artist = excluded.source_artist,
                source_title = excluded.source_title,
                target_media_file_id = excluded.target_media_file_id,
                strategy = excluded.strategy,
                confidence_score = excluded.confidence_score,
                resolved_at = excluded.resolved_at',
            [
                'sa' => trim($artist),
                'st' => trim($title),
                'san' => $artistNorm,
                'stn' => $titleNorm,
                'tid' => $mediaFileId,
                'strat' => $strategy,
                'conf' => $confidenceScore,
                'rat' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * No-op kept for backwards compatibility with callers
     * ({@see \App\LastFm\LastFmBufferProcessor},
     * {@see \App\Service\LastFmRematchService}). The previous implementation
     * maintained an in-memory map of un-flushed entities to dedup persists
     * within a batch; raw SQL UPSERT removes that need.
     */
    public function detachPending(): void
    {
    }

    /**
     * Drop the row matching this exact couple. Returns the number of rows
     * deleted (0 or 1). Called when the user creates / edits / deletes a
     * track-level alias for that couple.
     */
    public function purgeByCouple(string $artist, string $title): int
    {
        $artistNorm = NavidromeRepository::normalize($artist);
        $titleNorm = NavidromeRepository::normalize($title);
        if ($artistNorm === '' || $titleNorm === '') {
            return 0;
        }

        return (int) $this->connection()->executeStatement(
            'DELETE FROM lastfm_match_cache
             WHERE source_artist_norm = :a AND source_title_norm = :t',
            ['a' => $artistNorm, 't' => $titleNorm],
        );
    }

    /**
     * Drop every row whose source artist matches `$artist`. Called when the
     * user creates / edits an artist-level alias on `$artist` (the source
     * side of the alias) so the next cascade re-resolves through the new
     * alias.
     */
    public function purgeByArtist(string $artist): int
    {
        $artistNorm = NavidromeRepository::normalize($artist);
        if ($artistNorm === '') {
            return 0;
        }

        return (int) $this->connection()->executeStatement(
            'DELETE FROM lastfm_match_cache WHERE source_artist_norm = :a',
            ['a' => $artistNorm],
        );
    }

    /**
     * Drop every negative cache row older than `$ttlDays`. Called once at
     * the start of each import / rematch run. `$ttlDays <= 0` means
     * « never expire », so it's a no-op.
     */
    public function purgeStale(int $ttlDays): int
    {
        if ($ttlDays <= 0) {
            return 0;
        }
        $threshold = (new \DateTimeImmutable())
            ->modify(sprintf('-%d days', $ttlDays))
            ->format('Y-m-d H:i:s');

        return (int) $this->connection()->executeStatement(
            'DELETE FROM lastfm_match_cache
             WHERE target_media_file_id IS NULL AND resolved_at < :threshold',
            ['threshold' => $threshold],
        );
    }

    /**
     * Wipe the table. `$negativeOnly = true` keeps positive matches —
     * useful when one wants to retry the Last.fm `track.getInfo` step
     * for previously-missed scrobbles without losing known good
     * resolutions.
     */
    public function purgeAll(bool $negativeOnly = false): int
    {
        $sql = $negativeOnly
            ? 'DELETE FROM lastfm_match_cache WHERE target_media_file_id IS NULL'
            : 'DELETE FROM lastfm_match_cache';

        return (int) $this->connection()->executeStatement($sql);
    }

    private function connection(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }
}
