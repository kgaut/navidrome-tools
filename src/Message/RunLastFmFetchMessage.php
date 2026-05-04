<?php

namespace App\Message;

/**
 * Async dispatch of the Last.fm fetch flow ({@see App\LastFm\LastFmFetcher}).
 * Routed to the `async` transport (cf. config/packages/messenger.yaml) and
 * picked up by {@see App\MessageHandler\RunLastFmFetchHandler}, which
 * resumes the pre-created RunHistory (status=queued) and updates progress
 * as the stream advances.
 */
final class RunLastFmFetchMessage
{
    public function __construct(
        public readonly int $runHistoryId,
        public readonly string $apiKey,
        public readonly string $lastFmUser,
        public readonly ?string $dateMin = null,
        public readonly ?string $dateMax = null,
        public readonly ?int $maxScrobbles = null,
        public readonly bool $dryRun = false,
    ) {
    }
}
