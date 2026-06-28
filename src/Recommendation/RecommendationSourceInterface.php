<?php

namespace App\Recommendation;

/**
 * A recommendation engine plugin (Last.fm similar, ListenBrainz…), tagged
 * `app.recommendation_source` and aggregated by {@see RecommendationEngine}
 * via a tagged iterator. Mirrors the Notifier / Playlist plugin pattern.
 */
interface RecommendationSourceInterface
{
    /** Stable id, lowercase (e.g. "lastfm", "listenbrainz"). */
    public function getName(): string;

    /** False when the source lacks its config (API key / username) — skipped. */
    public function isConfigured(): bool;

    /**
     * Produce raw recommendations from my seed artists. Scores are
     * source-relative (the engine normalizes/merges across sources).
     *
     * @param list<ArtistSeed> $seeds
     *
     * @return list<array{name: string, mbid: ?string, score: float, seed: string}>
     */
    public function recommend(array $seeds): array;
}
