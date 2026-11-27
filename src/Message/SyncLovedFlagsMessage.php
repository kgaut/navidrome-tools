<?php

namespace App\Message;

/**
 * Flag stored listens as loved from the active source (issue #310).
 */
final class SyncLovedFlagsMessage
{
    public function __construct(
        public readonly bool $dryRun = false,
    ) {
    }
}
