<?php

namespace App\Playlist;

/**
 * Outcome of generating one playlist. Returned (one per definition) by
 * {@see PlaylistGenerator::generate()} for the command / handler to report.
 */
final class PlaylistRunResult
{
    public const ACTION_CREATED = 'created';
    public const ACTION_REPLACED = 'replaced';
    public const ACTION_EMPTY = 'empty';      // algorithm produced no tracks → nothing written
    public const ACTION_DRY_RUN = 'dry-run';  // computed, not written
    public const ACTION_ERROR = 'error';

    /**
     * @param list<string> $trackIds
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $action,
        public readonly array $trackIds = [],
        public readonly ?string $playlistId = null,
        public readonly ?string $error = null,
    ) {
    }

    public function trackCount(): int
    {
        return count($this->trackIds);
    }
}
