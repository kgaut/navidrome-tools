<?php

namespace App\Message;

final class FetchLastFmMessage
{
    public function __construct(
        public readonly string $user,
        public readonly string $apiKey,
        public readonly ?string $dateMin,
        public readonly ?string $dateMax,
        public readonly ?int $maxScrobbles,
        public readonly bool $dryRun,
    ) {
    }
}
