<?php

namespace App\Service;

/**
 * Outcome of an {@see AliasGenerator::generate()} run. Plain mutable counters
 * — the command turns them into a summary table.
 */
class AliasGenerationReport
{
    public bool $dryRun = false;

    /** Artist-level aliases created via the shared-MBID bridge. */
    public int $artistAliasesCreated = 0;
    /** Artist candidates skipped (already aliased). */
    public int $artistAliasesSkipped = 0;
    public int $playsCoveredArtist = 0;

    /** Distinct unmatched couples examined for a track alias. */
    public int $couplesConsidered = 0;
    /**
     * Couples skipped because a plain rematch would already resolve them
     * (an exact normalized (artist, title) exists in the library — the
     * unmatched status is stale, no alias needed).
     */
    public int $cascadeResolvable = 0;
    public int $trackAlbumExact = 0;
    public int $trackAlbumFuzzy = 0;
    public int $trackArtistFuzzy = 0;
    /** Couples with candidates but no unambiguous winner (skipped). */
    public int $trackAmbiguous = 0;
    /** Couples skipped because an alias already exists. */
    public int $trackExistingSkipped = 0;
    public int $playsCoveredTrack = 0;

    /**
     * A bounded sample of what was generated, for human review in the CLI.
     *
     * @var list<array{type: string, source: string, target: string, strategy: string, plays: int}>
     */
    public array $samples = [];

    public function trackAliasesCreated(): int
    {
        return $this->trackAlbumExact + $this->trackAlbumFuzzy + $this->trackArtistFuzzy;
    }

    public function totalCreated(): int
    {
        return $this->artistAliasesCreated + $this->trackAliasesCreated();
    }
}
