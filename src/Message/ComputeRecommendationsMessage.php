<?php

namespace App\Message;

/**
 * Async request to (re)compute the artist recommendations snapshot. Handled
 * by the Messenger worker because it fans out across Last.fm / ListenBrainz /
 * MusicBrainz (rate-limited), which is too slow for a web request. The result
 * is persisted by {@see \App\Recommendation\RecommendationStore} for the
 * review UI to display.
 */
final class ComputeRecommendationsMessage
{
    public function __construct(
        public readonly ?int $limit = null,
    ) {
    }
}
