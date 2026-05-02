<?php

namespace App\Tests\LastFm;

use App\Entity\LastFmArtistAlias;
use App\Navidrome\NavidromeRepository;
use App\Repository\LastFmArtistAliasRepository;

/**
 * Stub LastFmArtistAliasRepository that skips parent::__construct (no
 * Doctrine registry needed) and looks up aliases against an in-memory
 * list.
 */
final class InMemoryArtistAliasRepository extends LastFmArtistAliasRepository
{
    /** @param LastFmArtistAlias[] $aliases */
    public function __construct(private readonly array $aliases)
    {
        // Skip parent::__construct — we don't need the Doctrine registry.
    }

    public function findBySourceArtist(string $artist): ?LastFmArtistAlias
    {
        $norm = NavidromeRepository::normalize($artist);
        foreach ($this->aliases as $a) {
            if ($a->getSourceArtistNorm() === $norm) {
                return $a;
            }
        }

        return null;
    }

    public function resolve(string $artist): ?string
    {
        return $this->findBySourceArtist($artist)?->getTargetArtist();
    }
}
