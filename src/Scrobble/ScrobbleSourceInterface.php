<?php

namespace App\Scrobble;

use App\LastFm\LastFmScrobble;

/**
 * A service the listening history is fetched from (issue #310, ADR
 * 0310-scrobbles-listenbrainz-source-unique-raccord-par-horodatage).
 *
 * One source is active per instance (`SCROBBLE_SOURCE`). Every listen it
 * yields is stored in `scrobbles` with `source = name()` and
 * `lastfm_user = user()` (the column keeps its historical name).
 */
interface ScrobbleSourceInterface
{
    /** Stable identifier stored in `scrobbles.source` (`lastfm`, `listenbrainz`). */
    public function name(): string;

    /** Account the listens belong to, stored in `scrobbles.lastfm_user`. */
    public function user(): string;

    /** False when the credentials/user needed to fetch are missing. */
    public function isConfigured(): bool;

    /**
     * Listens played in [$dateMin, $dateMax], in any order. Implementations
     * stream page by page and must not yield the « now playing » entry.
     *
     * @return iterable<LastFmScrobble>
     */
    public function streamListens(?\DateTimeInterface $dateMin = null, ?\DateTimeInterface $dateMax = null): iterable;
}
