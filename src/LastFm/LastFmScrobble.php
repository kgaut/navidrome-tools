<?php

namespace App\LastFm;

final class LastFmScrobble
{
    public function __construct(
        public readonly string $artist,
        public readonly string $title,
        public readonly string $album,
        public readonly ?string $mbid,
        public readonly \DateTimeImmutable $playedAt,
    ) {
    }
}
