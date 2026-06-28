<?php

namespace App\Recommendation;

/**
 * A « seed » artist from my own listening, fed to the recommendation
 * sources. `weight` reflects how strongly it should pull recommendations
 * (volume / loved / recency combined by {@see SeedBuilder}).
 */
final class ArtistSeed
{
    public function __construct(
        public readonly string $name,
        public readonly float $weight,
        public readonly ?string $mbid = null,
    ) {
    }
}
