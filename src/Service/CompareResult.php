<?php

namespace App\Service;

final class CompareResult
{
    /**
     * @param array{period: string, label: string, total_plays: int, distinct_tracks: int} $period1
     * @param array{period: string, label: string, total_plays: int, distinct_tracks: int} $period2
     * @param list<array{artist: string, plays1: int, plays2: int, delta: int, status: string}> $artists
     * @param list<array{id: string, title: string, artist: string, album: string, plays1: int, plays2: int, delta: int, status: string}> $tracks
     */
    public function __construct(
        public readonly array $period1,
        public readonly array $period2,
        public readonly array $artists,
        public readonly array $tracks,
    ) {
    }
}
