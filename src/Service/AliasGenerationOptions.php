<?php

namespace App\Service;

/**
 * Knobs for {@see AliasGenerator::generate()}. Each strategy can be toggled
 * independently; the two fuzzy distances bound the Levenshtein tolerance of
 * the title match (artist / album are pinned exactly, so only the title is
 * fuzzed).
 */
final readonly class AliasGenerationOptions
{
    public function __construct(
        public string $target = 'navidrome',
        public bool $dryRun = false,
        public bool $artistMbid = true,
        public bool $albumExact = true,
        public bool $albumFuzzy = true,
        public bool $artistFuzzy = true,
        public int $albumFuzzyMaxDistance = 5,
        public int $artistFuzzyMaxDistance = 2,
        public int $limit = 0,
    ) {
    }
}
