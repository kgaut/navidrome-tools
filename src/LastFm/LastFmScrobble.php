<?php

namespace App\LastFm;

final class LastFmScrobble
{
    public function __construct(
        public readonly string $artist,
        public readonly string $title,
        public readonly string $album,
        public readonly string $albumArtist,
        public readonly ?string $mbidTrack,
        public readonly ?string $mbidArtist,
        public readonly ?string $mbidAlbum,
        public readonly \DateTimeImmutable $playedAt,
        public readonly bool $loved = false,
        public readonly ?string $imageUrl = null,
    ) {
    }
}
