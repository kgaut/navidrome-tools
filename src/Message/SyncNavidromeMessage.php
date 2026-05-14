<?php

namespace App\Message;

final class SyncNavidromeMessage
{
    public function __construct(
        public readonly int $limit,
        public readonly bool $dryRun,
        public readonly int $toleranceSeconds,
        public readonly bool $autoStop,
    ) {
    }
}
