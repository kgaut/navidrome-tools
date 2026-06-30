<?php

namespace App\Navidrome;

final class NavidromeSyncReport
{
    public int $prepared = 0;
    public int $considered = 0;
    public int $matched = 0;
    public int $duplicates = 0;
    public int $unmatched = 0;
    public int $skipped = 0;
    public bool $dryRun = false;
    public ?string $backupPath = null;
    /** Number of intermediate (checkpoint) backups taken during the run. */
    public int $intermediateBackups = 0;
    /** Scrobbles skipped because a Last.fm API call failed (left pending). */
    public int $apiErrors = 0;
    /** True when the run stopped early after too many consecutive API errors. */
    public bool $abortedOnApiErrors = false;
}
