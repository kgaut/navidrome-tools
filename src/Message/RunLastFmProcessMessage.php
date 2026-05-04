<?php

namespace App\Message;

/**
 * Async dispatch of the Last.fm buffer processing flow
 * ({@see App\LastFm\LastFmBufferProcessor}). The handler will pre-flight the
 * Navidrome container (auto-stop) before writing.
 */
final class RunLastFmProcessMessage
{
    public function __construct(
        public readonly int $runHistoryId,
        public readonly int $limit = 0,
        public readonly int $toleranceSeconds = 60,
        public readonly bool $dryRun = false,
    ) {
    }
}
