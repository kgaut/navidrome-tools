<?php

namespace App\Message;

/**
 * Moves already-written scrobbles to the track the current cascade picks
 * (issue #285). Handled by the worker, Navidrome stopped. Filters as in
 * {@see \App\Service\MatchedRelinker::plan()}.
 */
final class RelinkMatchedMessage
{
    public function __construct(
        public readonly ?string $artist,
        public readonly ?string $title,
        public readonly ?string $album,
        public readonly ?string $fromAlbum,
        public readonly int $limit,
        public readonly bool $contains = false,
    ) {
    }
}
