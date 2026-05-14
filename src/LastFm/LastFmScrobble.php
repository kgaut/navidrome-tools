<?php

namespace App\LastFm;

/**
 * Lightweight DTO used by the matching cascade (ScrobbleMatcher).
 * For storage, see App\Entity\Scrobble which carries the full API payload.
 */
final class LastFmScrobble
{
    public function __construct(
        public readonly string $artist,
        public readonly string $title,
        public readonly string $album,
        public readonly ?string $mbid,
        public readonly \DateTimeImmutable $playedAt,
        // Additional fields stored in Scrobble but not used by ScrobbleMatcher.
        public readonly string $albumArtist = '',
        public readonly ?string $mbidArtist = null,
        public readonly ?string $mbidAlbum = null,
        public readonly bool $loved = false,
        public readonly ?string $imageUrl = null,
    ) {
    }
}
