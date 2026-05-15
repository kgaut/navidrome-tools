<?php

namespace App\Message;

final class SyncStrawberryMessage
{
    public function __construct(
        public readonly int $limit,
        public readonly bool $dryRun,
        public readonly bool $retryUnmatched,
    ) {
    }
}
