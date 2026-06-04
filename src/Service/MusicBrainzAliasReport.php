<?php

namespace App\Service;

/**
 * Aggregate counters + sample suggestions for one run of
 * {@see MusicBrainzAliasSuggester}.
 */
final class MusicBrainzAliasReport
{
    public bool $dryRun = false;
    public int $artistsConsidered = 0;
    public int $artistsQueried = 0;
    public int $skippedAlreadyAliased = 0;
    public int $skippedAlreadyOwned = 0;
    public int $aliasesCreated = 0;
    public int $playsCovered = 0;
    public int $ambiguous = 0;
    public int $noMatch = 0;
    public int $mbErrors = 0;

    /** @var list<MusicBrainzAliasSuggestion> */
    public array $samples = [];

    public function addSample(MusicBrainzAliasSuggestion $s, int $cap = 30): void
    {
        if (count($this->samples) < $cap) {
            $this->samples[] = $s;
        }
    }
}
