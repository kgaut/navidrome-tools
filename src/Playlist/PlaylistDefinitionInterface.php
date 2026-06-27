<?php

namespace App\Playlist;

/**
 * A single playlist « algorithm » plugin. Each implementation is a Symfony
 * service tagged `app.playlist_definition` (see config/services.yaml) and
 * discovered by {@see PlaylistGenerator} via a tagged iterator — the exact
 * pattern used by {@see \App\Notifier\NotifierDriverInterface}.
 *
 * Implementations are PURE selection logic: `build()` returns an ordered
 * list of Navidrome media_file ids (= Subsonic song ids). Writing the
 * playlist to Navidrome is the orchestrator's job, so algorithms stay
 * testable without any Subsonic / HTTP dependency.
 */
interface PlaylistDefinitionInterface
{
    /**
     * Stable identifier, lowercase-kebab (e.g. "retour-en-arriere"). Used
     * as the CLI `--slug` value and the `PLAYLISTS_ENABLED` CSV token.
     */
    public function getSlug(): string;

    /** Human name written to Navidrome as the playlist title. */
    public function getName(): string;

    /** One-line description, written to Navidrome as the playlist comment. */
    public function getDescription(): string;

    /**
     * Build the ordered track list. The algorithm owns the ordering,
     * shuffling included.
     *
     * @return list<string> media_file ids, in playlist order
     */
    public function build(PlaylistContext $context): array;
}
