<?php

namespace App\Strawberry;

final class StrawberrySyncReport
{
    public int $prepared = 0;
    public int $considered = 0;
    public int $matched = 0;
    public int $unmatched = 0;
    public bool $dryRun = false;
    public bool $retryUnmatched = false;
}
