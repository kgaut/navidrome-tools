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
}
