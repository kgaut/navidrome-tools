<?php

namespace App\Message;

final class RematchMessage
{
    public function __construct(
        public readonly string $target,
        public readonly int $limit,
        public readonly bool $dryRun,
        public readonly bool $autoStop,
    ) {
    }
}
