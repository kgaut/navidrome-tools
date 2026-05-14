<?php

namespace App\LastFm;

/**
 * One entry of a Last.fm `user.getLovedTracks` page. Mirrors
 * {@see LastFmScrobble} but the timestamp is the date the user loved
 * the track, not when they played it.
 */
final class LastFmLovedTrack
{
    public function __construct(
        public readonly string $artist,
        public readonly string $title,
        public readonly ?string $mbid,
        public readonly ?\DateTimeImmutable $lovedAt,
    ) {
    }
}
