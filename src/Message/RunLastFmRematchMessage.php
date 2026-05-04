<?php

namespace App\Message;

/**
 * Async dispatch of the rematch flow ({@see App\Service\LastFmRematchService}).
 * The handler will pre-flight the Navidrome container (auto-stop) before
 * writing.
 */
final class RunLastFmRematchMessage
{
    public function __construct(
        public readonly int $runHistoryId,
        public readonly ?int $runIdFilter = null,
        public readonly int $limit = 0,
        public readonly int $toleranceSeconds = 60,
        public readonly bool $random = false,
        public readonly bool $dryRun = false,
    ) {
    }
}
