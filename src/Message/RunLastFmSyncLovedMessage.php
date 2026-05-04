<?php

namespace App\Message;

/**
 * Async dispatch of the loved↔starred sync flow
 * ({@see App\LastFm\LovedStarredSyncService}). No fine-grained progress —
 * the handler reports phase boundaries only (collecting / syncing).
 */
final class RunLastFmSyncLovedMessage
{
    public function __construct(
        public readonly int $runHistoryId,
        public readonly string $direction,
        public readonly bool $dryRun = true,
    ) {
    }
}
