<?php

namespace App\Tests\LastFm;

use App\Entity\LastFmAlias;
use App\Navidrome\NavidromeRepository;
use App\Repository\LastFmAliasRepository;

/**
 * Stub LastFmAliasRepository that skips parent::__construct (no Doctrine
 * registry needed) and looks up aliases against an in-memory list.
 */
final class InMemoryAliasRepository extends LastFmAliasRepository
{
    /** @param LastFmAlias[] $aliases */
    public function __construct(private readonly array $aliases)
    {
        // Skip parent::__construct — we don't need the Doctrine registry.
    }

    public function findByScrobble(string $artist, string $title): ?LastFmAlias
    {
        $artistN = NavidromeRepository::normalize($artist);
        $titleN = NavidromeRepository::normalize($title);
        foreach ($this->aliases as $a) {
            if ($a->getSourceArtistNorm() === $artistN && $a->getSourceTitleNorm() === $titleN) {
                return $a;
            }
        }

        return null;
    }
}
