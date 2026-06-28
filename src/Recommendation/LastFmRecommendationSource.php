<?php

namespace App\Recommendation;

use App\LastFm\LastFmApiException;
use App\LastFm\LastFmClient;

/**
 * Last.fm `artist.getSimilar` recommendation source. For each seed artist we
 * ask Last.fm for its neighbours and accumulate a score = Σ(match × seed
 * weight): an artist surfaced by several strong seeds floats up. MBIDs come
 * straight from Last.fm when present (the engine resolves the rest).
 *
 * Per-seed failures are swallowed (one dead seed mustn't sink the run); a
 * missing API key makes the source unconfigured (skipped by the engine).
 */
final class LastFmRecommendationSource implements RecommendationSourceInterface
{
    public function __construct(
        private readonly LastFmClient $client,
        #[\SensitiveParameter]
        private readonly string $apiKey = '',
        private readonly int $perSeedLimit = 20,
    ) {
    }

    public function getName(): string
    {
        return 'lastfm';
    }

    public function isConfigured(): bool
    {
        return trim($this->apiKey) !== '';
    }

    public function recommend(array $seeds): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        /** @var array<string, array{name: string, mbid: ?string, score: float, seed: string}> $acc */
        $acc = [];

        foreach ($seeds as $seed) {
            try {
                $similar = $this->client->artistGetSimilar($this->apiKey, $seed->name, $this->perSeedLimit);
            } catch (LastFmApiException) {
                // A bad / unknown seed name shouldn't abort the whole run.
                continue;
            }

            foreach ($similar as $row) {
                $name = trim($row['name']);
                if ($name === '') {
                    continue;
                }
                $key = mb_strtolower($name);
                $contribution = $row['match'] * $seed->weight;

                if (!isset($acc[$key])) {
                    $acc[$key] = [
                        'name' => $name,
                        'mbid' => $row['mbid'],
                        'score' => 0.0,
                        'seed' => $seed->name,
                    ];
                }
                $acc[$key]['score'] += $contribution;
                // Backfill an MBID if a later neighbour carries one.
                if ($acc[$key]['mbid'] === null && $row['mbid'] !== null) {
                    $acc[$key]['mbid'] = $row['mbid'];
                }
                // Attribute to the strongest seed seen so far.
                if ($contribution > 0 && $seed->weight > 0) {
                    $acc[$key]['seed'] = $this->strongerSeed($acc[$key]['seed'], $seed->name, $seeds);
                }
            }
        }

        return array_values($acc);
    }

    /**
     * Keep whichever of the two seeds has the higher weight, so the « parce
     * que tu écoutes X » attribution points at the most relevant library
     * artist. Falls back to the incumbent when weights can't be compared.
     *
     * @param list<ArtistSeed> $seeds
     */
    private function strongerSeed(string $current, string $candidate, array $seeds): string
    {
        $weights = [];
        foreach ($seeds as $s) {
            $weights[$s->name] = $s->weight;
        }

        return ($weights[$candidate] ?? 0) > ($weights[$current] ?? 0) ? $candidate : $current;
    }
}
