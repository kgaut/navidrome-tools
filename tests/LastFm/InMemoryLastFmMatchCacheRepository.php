<?php

namespace App\Tests\LastFm;

use App\Entity\LastFmMatchCacheEntry;
use App\Navidrome\NavidromeRepository;
use App\Repository\LastFmMatchCacheRepository;

/**
 * Stub LastFmMatchCacheRepository that skips parent::__construct (no
 * Doctrine registry needed) and stores entries in memory. Mirrors the
 * patterns of {@see InMemoryAliasRepository} / {@see InMemoryArtistAliasRepository}.
 */
final class InMemoryLastFmMatchCacheRepository extends LastFmMatchCacheRepository
{
    /** @var array<string, LastFmMatchCacheEntry> keyed by "artistNorm\0titleNorm" */
    private array $byKey = [];

    public function __construct()
    {
        // Skip parent::__construct — we don't need the Doctrine registry.
    }

    public function findByCouple(string $artist, string $title): ?LastFmMatchCacheEntry
    {
        $key = $this->key($artist, $title);
        if ($key === null) {
            return null;
        }

        return $this->byKey[$key] ?? null;
    }

    public function recordPositive(
        string $artist,
        string $title,
        string $mediaFileId,
        string $strategy,
        ?int $confidenceScore = null,
    ): void {
        $key = $this->key($artist, $title);
        if ($key === null) {
            return;
        }
        $this->byKey[$key] = new LastFmMatchCacheEntry($artist, $title, $mediaFileId, $strategy, $confidenceScore);
    }

    public function recordNegative(string $artist, string $title): void
    {
        $key = $this->key($artist, $title);
        if ($key === null) {
            return;
        }
        $this->byKey[$key] = new LastFmMatchCacheEntry($artist, $title, null, LastFmMatchCacheEntry::STRATEGY_NEGATIVE);
    }

    public function purgeByCouple(string $artist, string $title): int
    {
        $key = $this->key($artist, $title);
        if ($key === null || !isset($this->byKey[$key])) {
            return 0;
        }
        unset($this->byKey[$key]);

        return 1;
    }

    public function purgeByArtist(string $artist): int
    {
        $artistNorm = NavidromeRepository::normalize($artist);
        if ($artistNorm === '') {
            return 0;
        }
        $deleted = 0;
        foreach ($this->byKey as $key => $entry) {
            if ($entry->getSourceArtistNorm() === $artistNorm) {
                unset($this->byKey[$key]);
                ++$deleted;
            }
        }

        return $deleted;
    }

    public function purgeStale(int $ttlDays): int
    {
        if ($ttlDays <= 0) {
            return 0;
        }
        $deleted = 0;
        foreach ($this->byKey as $key => $entry) {
            if (!$entry->isPositive() && $entry->isStale($ttlDays)) {
                unset($this->byKey[$key]);
                ++$deleted;
            }
        }

        return $deleted;
    }

    public function purgeAll(bool $negativeOnly = false): int
    {
        if (!$negativeOnly) {
            $deleted = count($this->byKey);
            $this->byKey = [];

            return $deleted;
        }
        $deleted = 0;
        foreach ($this->byKey as $key => $entry) {
            if (!$entry->isPositive()) {
                unset($this->byKey[$key]);
                ++$deleted;
            }
        }

        return $deleted;
    }

    /**
     * Test helper: count rows currently in the cache.
     */
    public function size(): int
    {
        return count($this->byKey);
    }

    /**
     * Test helper: seed an entry with an explicit `resolvedAt` so we can
     * assert TTL-based purge / stale handling without time-travel hacks.
     */
    public function seed(LastFmMatchCacheEntry $entry, ?\DateTimeImmutable $resolvedAt = null): void
    {
        if ($resolvedAt !== null) {
            $reflection = new \ReflectionProperty(LastFmMatchCacheEntry::class, 'resolvedAt');
            $reflection->setValue($entry, $resolvedAt);
        }
        $key = $entry->getSourceArtistNorm() . "\0" . $entry->getSourceTitleNorm();
        $this->byKey[$key] = $entry;
    }

    private function key(string $artist, string $title): ?string
    {
        $artistNorm = NavidromeRepository::normalize($artist);
        $titleNorm = NavidromeRepository::normalize($title);
        if ($artistNorm === '' || $titleNorm === '') {
            return null;
        }

        return $artistNorm . "\0" . $titleNorm;
    }
}
