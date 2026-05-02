<?php

namespace App\LastFm;

final class RematchReport
{
    public int $considered = 0;
    public int $matchedAsInserted = 0;
    public int $matchedAsDuplicate = 0;
    public int $skipped = 0;
    public int $stillUnmatched = 0;

    /** @see ImportReport — same observability counters for rematch runs. */
    public int $cacheHitsPositive = 0;
    public int $cacheHitsNegative = 0;
    public int $cacheMisses = 0;

    /** Number of stale rows whose previous match status changed to «inserted ». */
    public function changedCount(): int
    {
        return $this->matchedAsInserted + $this->matchedAsDuplicate + $this->skipped;
    }
}
