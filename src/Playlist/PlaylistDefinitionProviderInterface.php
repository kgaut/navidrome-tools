<?php

namespace App\Playlist;

/**
 * Supplies playlist definitions that live in data rather than in code
 * (e.g. the user's described playlists, issue #258). Tagged
 * `app.playlist_definition_provider` and queried by
 * {@see PlaylistGenerator} on every call, so rows added or removed take
 * effect without a container rebuild.
 */
interface PlaylistDefinitionProviderInterface
{
    /**
     * @return iterable<PlaylistDefinitionInterface>
     */
    public function getDefinitions(): iterable;
}
