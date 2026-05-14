<?php

namespace App\Navidrome;

final class TrackSummary
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $artist,
        public readonly string $album,
        public readonly int $duration,
        public readonly int $plays,
    ) {
    }
}
