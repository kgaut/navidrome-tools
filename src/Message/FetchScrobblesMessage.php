<?php

namespace App\Message;

/**
 * Fetch listens from the active source, launched from the web import page
 * (issue #310).
 */
final class FetchScrobblesMessage
{
    public function __construct(
        public readonly ?string $dateMin = null,
        public readonly ?string $dateMax = null,
        public readonly ?int $maxScrobbles = null,
        public readonly bool $dryRun = false,
    ) {
    }
}
