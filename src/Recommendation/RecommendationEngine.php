<?php

namespace App\Recommendation;

use App\Lidarr\LidarrClient;
use App\MusicBrainz\MusicBrainzClient;
use App\MusicBrainz\MusicBrainzException;
use App\Navidrome\NavidromeRepository;

/**
 * Aggregates every configured {@see RecommendationSourceInterface} into one
 * ranked list of artists to (maybe) add to Lidarr.
 *
 * Pipeline:
 *   1. build weighted seeds from my listening ({@see SeedBuilder});
 *   2. collect raw candidates from each configured source;
 *   3. merge by normalized name (sum scores, union sources & seeds);
 *   4. drop artists I already own / seed with / have ignored;
 *   5. walk the ranked list top-down, resolving a missing MBID via
 *      MusicBrainz only for the candidates we actually keep, skipping those
 *      already in Lidarr, until the cap is reached.
 *
 * MusicBrainz throttling is the caller's job: pass `$beforeMbQuery` (a sleep)
 * — MB rate-limits at 1 req/s per UA.
 */
class RecommendationEngine
{
    /** Minimum MB search score (0-100) we'll trust when resolving an MBID. */
    private const MIN_MB_SCORE = 85;

    /**
     * @param iterable<RecommendationSourceInterface> $sources
     */
    public function __construct(
        private readonly iterable $sources,
        private readonly SeedBuilder $seedBuilder,
        private readonly NavidromeRepository $navidrome,
        private readonly MusicBrainzClient $musicBrainz,
        private readonly LidarrClient $lidarr,
        private readonly RecommendationStore $store,
        private readonly int $seedLimit = 25,
        private readonly int $defaultLimit = 50,
    ) {
    }

    /**
     * @param ?callable(string): void $beforeMbQuery called right before each
     *                                               MusicBrainz lookup (throttle host)
     */
    public function compute(?int $limit = null, ?callable $beforeMbQuery = null): RecommendationResult
    {
        $limit = $limit !== null && $limit > 0 ? $limit : $this->defaultLimit;

        $seeds = $this->seedBuilder->build($this->seedLimit);
        if ($seeds === []) {
            return new RecommendationResult([], 0, 0, 0);
        }

        $merged = $this->mergeSources($seeds);
        $rawCount = count($merged);

        // Exclusion sets (by normalized name).
        $owned = $this->navidrome->getKnownArtistsNormalized();
        $ignoredNames = $this->store->ignoredNames();
        $seedNorms = [];
        foreach ($seeds as $s) {
            $seedNorms[NavidromeRepository::normalize($s->name)] = true;
        }

        // Exclusion sets (by MBID).
        $lidarrMbids = $this->lidarrExistingMbids();
        $ignoredMbids = $this->store->ignoredMbids();

        // Rank by aggregated score, strongest first.
        usort($merged, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $out = [];
        $mbLookups = 0;
        foreach ($merged as $cand) {
            if (count($out) >= $limit) {
                break;
            }

            $norm = NavidromeRepository::normalize($cand['name']);
            if ($norm === '' || isset($owned[$norm]) || isset($seedNorms[$norm]) || isset($ignoredNames[$norm])) {
                continue;
            }

            $mbid = $cand['mbid'];
            if ($mbid === null) {
                $mbid = $this->resolveMbid($cand['name'], $beforeMbQuery, $mbLookups);
            }

            if ($mbid !== null && (isset($lidarrMbids[$mbid]) || isset($ignoredMbids[$mbid]))) {
                continue;
            }

            $seedsList = array_values(array_unique($cand['seeds']));
            $sourcesList = array_values(array_unique($cand['sources']));
            $out[] = new ArtistRecommendation($cand['name'], $mbid, $cand['score'], $sourcesList, $seedsList);
        }

        return new RecommendationResult($out, count($seeds), $rawCount, $mbLookups);
    }

    /**
     * Collect every configured source and fold candidates together by
     * normalized name: scores add up, sources/seeds union, the first MBID wins.
     *
     * @param list<ArtistSeed> $seeds
     *
     * @return list<array{name: string, mbid: ?string, score: float, sources: list<string>, seeds: list<string>}>
     */
    private function mergeSources(array $seeds): array
    {
        /** @var array<string, array{name: string, mbid: ?string, score: float, sources: list<string>, seeds: list<string>}> $acc */
        $acc = [];

        foreach ($this->sources as $source) {
            if (!$source->isConfigured()) {
                continue;
            }
            foreach ($source->recommend($seeds) as $row) {
                $name = trim($row['name']);
                $norm = NavidromeRepository::normalize($name);
                if ($norm === '') {
                    continue;
                }
                if (!isset($acc[$norm])) {
                    $acc[$norm] = ['name' => $name, 'mbid' => null, 'score' => 0.0, 'sources' => [], 'seeds' => []];
                }
                $acc[$norm]['score'] += $row['score'];
                $acc[$norm]['sources'][] = $source->getName();
                if ($row['seed'] !== '') {
                    $acc[$norm]['seeds'][] = $row['seed'];
                }
                if ($acc[$norm]['mbid'] === null && $row['mbid'] !== null && $row['mbid'] !== '') {
                    $acc[$norm]['mbid'] = $row['mbid'];
                }
            }
        }

        return array_values($acc);
    }

    /**
     * Resolve an MBID for a name via MusicBrainz, accepting only a confident
     * top hit. Returns null on no match, low score, or a transient MB error
     * (a missing MBID just means « can't add to Lidarr yet », not a failure).
     *
     * @param ?callable(string): void $beforeMbQuery
     */
    private function resolveMbid(string $name, ?callable $beforeMbQuery, int &$mbLookups): ?string
    {
        if ($beforeMbQuery !== null) {
            $beforeMbQuery($name);
        }
        ++$mbLookups;

        try {
            $candidates = $this->musicBrainz->searchArtist($name, 3);
        } catch (MusicBrainzException) {
            return null;
        }

        $best = $candidates[0] ?? null;
        if ($best === null || $best->score < self::MIN_MB_SCORE) {
            return null;
        }

        return $best->mbid;
    }

    /**
     * @return array<string, true>
     */
    private function lidarrExistingMbids(): array
    {
        if (!$this->lidarr->isConfigured()) {
            return [];
        }
        try {
            return $this->lidarr->existingArtistMbids();
        } catch (\Throwable) {
            // Lidarr unreachable shouldn't sink the whole recommendation run;
            // worst case we recommend an artist already present (the add is
            // idempotent on Lidarr's side anyway).
            return [];
        }
    }
}
