<?php

namespace App\LastFm;

final class ProcessReport
{
    /** Buffered scrobbles read from lastfm_import_buffer this run. */
    public int $considered = 0;

    /** Matched scrobbles inserted into Navidrome's scrobbles table. */
    public int $inserted = 0;

    /** Matched scrobbles already present in Navidrome (within tolerance). */
    public int $duplicates = 0;

    /** Scrobbles the matching cascade could not resolve. */
    public int $unmatched = 0;

    /** Scrobbles short-circuited by an alias rule. */
    public int $skipped = 0;

    /** Same observability counters as the rematch flow. */
    public int $cacheHitsPositive = 0;
    public int $cacheHitsNegative = 0;
    public int $cacheMisses = 0;
}
