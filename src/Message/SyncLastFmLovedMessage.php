<?php

namespace App\Message;

final class SyncLastFmLovedMessage
{
    public function __construct(
        public readonly string $user,
        public readonly string $apiKey,
        public readonly bool $dryRun = false,
    ) {
    }
}
