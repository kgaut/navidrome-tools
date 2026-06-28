<?php

namespace App\Recommendation;

/**
 * ListenBrainz recommendation source. Unlike Last.fm (seed → similar), LB's
 * recommendations are already personalized for the user server-side, so the
 * seeds are ignored: we fetch the user's recommended recordings, resolve each
 * to its artist(s), and aggregate scores by artist.
 *
 * Raw LB scores are small cosine-like floats (~0-1); they're scaled by
 * {@see self::SCORE_SCALE} so a strong LB pick weighs comparably to a Last.fm
 * match × seed-weight when the engine sums sources together.
 */
final class ListenBrainzRecommendationSource implements RecommendationSourceInterface
{
    /** Bring LB's ~0-1 scores onto the same order of magnitude as Last.fm's. */
    private const SCORE_SCALE = 100.0;

    public function __construct(
        private readonly ListenBrainzClient $client,
        private readonly int $count = 100,
    ) {
    }

    public function getName(): string
    {
        return 'listenbrainz';
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function recommend(array $seeds): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        try {
            $recordings = $this->client->recommendedRecordings($this->count);
        } catch (ListenBrainzException) {
            return [];
        }
        if ($recordings === []) {
            return [];
        }

        $scoreByRecording = [];
        foreach ($recordings as $rec) {
            $scoreByRecording[$rec['recording_mbid']] = $rec['score'];
        }

        try {
            $artistsByRecording = $this->client->resolveArtists(array_keys($scoreByRecording));
        } catch (ListenBrainzException) {
            return [];
        }

        /** @var array<string, array{name: string, mbid: string, score: float}> $acc */
        $acc = [];
        foreach ($artistsByRecording as $recordingMbid => $artists) {
            $score = ($scoreByRecording[$recordingMbid] ?? 0.0) * self::SCORE_SCALE;
            foreach ($artists as $artist) {
                $mbid = $artist['mbid'];
                if (!isset($acc[$mbid])) {
                    $acc[$mbid] = ['name' => $artist['name'], 'mbid' => $mbid, 'score' => 0.0];
                }
                $acc[$mbid]['score'] += $score;
            }
        }

        $out = [];
        foreach ($acc as $row) {
            $out[] = ['name' => $row['name'], 'mbid' => $row['mbid'], 'score' => $row['score'], 'seed' => ''];
        }

        return $out;
    }
}
