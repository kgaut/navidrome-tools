<?php

namespace App\Loves;

use App\LastFm\LastFmLovedTrack;

/**
 * A service holding the user's « loved » tracks (issue #310): Last.fm loved
 * tracks or ListenBrainz feedback. One provider is active, the same as the
 * listen source (`SCROBBLE_SOURCE`).
 */
interface LovesProviderInterface
{
    /** `lastfm`, `listenbrainz`. */
    public function name(): string;

    public function user(): string;

    /** False when the user (or key) needed to read the loved tracks is missing. */
    public function isConfigured(): bool;

    /** False when writing a love is impossible (no session key, no token). */
    public function canWrite(): bool;

    /**
     * @return iterable<LastFmLovedTrack>
     */
    public function iterateLoved(): iterable;

    /**
     * Identifier of the track on the provider, or null when it cannot be
     * identified there. Read-only (may fill a local lookup cache): a dry-run
     * uses it to forecast what love() would do (#319).
     */
    public function identify(string $artist, string $title, ?string $recordingMbid): ?string;

    /**
     * Love a track. Returns false when the track cannot be identified on the
     * provider (nothing written); throws on a transport/API error.
     */
    public function love(string $artist, string $title, ?string $recordingMbid): bool;
}
