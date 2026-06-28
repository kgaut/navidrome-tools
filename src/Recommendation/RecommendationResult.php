<?php

namespace App\Recommendation;

/**
 * Outcome of one {@see RecommendationEngine::compute()} run: the ranked
 * recommendations plus a few counters for the RunHistory metrics / CLI
 * report (how many seeds drove it, how many raw candidates were merged,
 * how many MBIDs we had to resolve via MusicBrainz).
 */
final class RecommendationResult
{
    /**
     * @param list<ArtistRecommendation> $recommendations ranked, capped, MBID-resolved
     */
    public function __construct(
        public readonly array $recommendations,
        public readonly int $seedCount = 0,
        public readonly int $rawCandidates = 0,
        public readonly int $mbidLookups = 0,
    ) {
    }

    public function count(): int
    {
        return count($this->recommendations);
    }
}
