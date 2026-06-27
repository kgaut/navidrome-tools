<?php

namespace App\Message;

/**
 * Async request to (re)generate playlists in Navidrome. Handled by the
 * Messenger worker so the web request returns immediately — generation
 * reads the scrobble history and writes to Navidrome over HTTP, which can
 * take a moment on large libraries.
 *
 * `$slug === null` regenerates every ENABLED playlist; a slug regenerates
 * just that one (the per-row UI button), bypassing the enable list.
 */
final class GeneratePlaylistsMessage
{
    public function __construct(
        public readonly ?string $slug = null,
    ) {
    }
}
