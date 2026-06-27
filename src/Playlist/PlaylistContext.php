<?php

namespace App\Playlist;

/**
 * Shared inputs handed to every {@see PlaylistDefinitionInterface::build()}.
 * Keeps the algorithms pure / testable: `now` is injected rather than read
 * from the wall clock, so date-based generators (e.g. the anniversary
 * windows of « Retour en arrière ») can be exercised deterministically.
 */
final class PlaylistContext
{
    public function __construct(
        public readonly \DateTimeImmutable $now,
        public readonly int $defaultLimit = 50,
    ) {
    }
}
