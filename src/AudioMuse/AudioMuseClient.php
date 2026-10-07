<?php

namespace App\AudioMuse;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin client for a self-hosted AudioMuse-AI instance, which performs sonic
 * analysis of the library and can return sonically similar tracks.
 *
 * Endpoints used:
 *   GET /api/similar_tracks?item_id=…&n=… → [{item_id, …, distance}]
 *   GET /api/find_path?start_song_id=…&end_song_id=…&max_steps=…
 *       → {"path": [{item_id, …}], "total_distance": float}
 *   POST /api/clap/search {"query": …, "limit": …}
 *       → {"query": …, "results": [{item_id, …, similarity}], "count": int}
 *
 * Recent versions (≥ 3.6) return the list at the JSON root; older ones
 * wrapped it as {"similar_songs": […]}. Both shapes are accepted.
 *
 * Crucially, AudioMuse-AI indexes the library through the Navidrome/Subsonic
 * API, so the `item_id` it returns IS the Navidrome media_file id — usable
 * directly as a playlist song id, no remapping needed.
 *
 * The optional API key is AudioMuse's `API_TOKEN`, sent as
 * `Authorization: Bearer …` only when set (AudioMuse runs unauthenticated
 * while `AUTH_ENABLED` is off). `isConfigured()` is true as soon as a base
 * URL is provided.
 *
 * Errors: HTTP 404 (unknown / not analysed track) raises
 * AudioMuseNotFoundException, 401/403 AudioMuseAuthException, anything else
 * AudioMuseException.
 */
class AudioMuseClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl = '',
        #[\SensitiveParameter]
        private readonly string $apiKey = '',
    ) {
    }

    public function isConfigured(): bool
    {
        return trim($this->baseUrl) !== '';
    }

    /**
     * Sonically similar tracks for a seed media_file id, closest first.
     * `eliminate_duplicates` caps repeats of the same artist so the result
     * spreads across the library rather than clustering on one act.
     *
     * @return list<array{item_id: string, distance: float}>
     */
    public function similarTracks(string $itemId, int $n): array
    {
        if (!$this->isConfigured()) {
            throw new AudioMuseException('AudioMuse base URL is not set (AUDIOMUSE_BASE_URL).');
        }

        $payload = $this->get('/api/similar_tracks', [
            'item_id' => $itemId,
            'n' => max(1, $n),
            'eliminate_duplicates' => 'true',
        ]);

        $songs = array_is_list($payload) ? $payload : ($payload['similar_songs'] ?? []);
        if (!is_array($songs)) {
            return [];
        }

        $out = [];
        foreach ($songs as $song) {
            if (!is_array($song)) {
                continue;
            }
            $id = trim((string) ($song['item_id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $out[] = ['item_id' => $id, 'distance' => (float) ($song['distance'] ?? 0)];
        }

        return $out;
    }

    /**
     * A smooth sequence of tracks gliding from `$startId` to `$endId`
     * through sonically close songs, in path order. `$fixSize` asks
     * AudioMuse for exactly `$maxSteps` songs instead of « at most ».
     *
     * A 404 (no path within `$maxSteps`, or unknown track) raises
     * AudioMuseNotFoundException.
     *
     * @return list<string> media_file ids, start to end
     */
    public function findPath(string $startId, string $endId, int $maxSteps, bool $fixSize = true): array
    {
        if (!$this->isConfigured()) {
            throw new AudioMuseException('AudioMuse base URL is not set (AUDIOMUSE_BASE_URL).');
        }

        $payload = $this->get('/api/find_path', [
            'start_song_id' => $startId,
            'end_song_id' => $endId,
            'max_steps' => max(2, $maxSteps),
            'path_fix_size' => $fixSize ? 'true' : 'false',
        ]);

        $path = $payload['path'] ?? [];
        if (!is_array($path)) {
            return [];
        }

        $out = [];
        foreach ($path as $song) {
            $id = is_array($song) ? trim((string) ($song['item_id'] ?? '')) : '';
            if ($id !== '') {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * Free-text search over the library (CLAP text-to-audio model), best
     * match first: « piano calme, pluie », « rock énervé années 90 »…
     * The text model loads on demand, so the first call can be slow.
     *
     * @return list<array{item_id: string, similarity: float}>
     */
    public function textSearch(string $query, int $limit): array
    {
        if (!$this->isConfigured()) {
            throw new AudioMuseException('AudioMuse base URL is not set (AUDIOMUSE_BASE_URL).');
        }

        $payload = $this->post('/api/clap/search', ['query' => $query, 'limit' => max(1, $limit)], 120);

        $results = $payload['results'] ?? [];
        if (!is_array($results)) {
            return [];
        }

        $out = [];
        foreach ($results as $song) {
            $id = is_array($song) ? trim((string) ($song['item_id'] ?? '')) : '';
            if ($id !== '') {
                $out[] = ['item_id' => $id, 'similarity' => (float) ($song['similarity'] ?? 0)];
            }
        }

        return $out;
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return array<mixed>
     */
    private function get(string $path, array $query): array
    {
        return $this->request('GET', $path, ['query' => $query]);
    }

    /**
     * @param array<string, mixed> $json
     *
     * @return array<mixed>
     */
    private function post(string $path, array $json, int $timeout): array
    {
        return $this->request('POST', $path, ['json' => $json, 'timeout' => $timeout]);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     */
    private function request(string $method, string $path, array $options): array
    {
        $headers = ['Accept' => 'application/json'];
        if (trim($this->apiKey) !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }

        $url = rtrim($this->baseUrl, '/') . $path;

        try {
            $response = $this->httpClient->request($method, $url, $options + [
                'headers' => $headers,
                'timeout' => 60,
            ]);
            $status = $response->getStatusCode();
            if ($status === 401 || $status === 403) {
                throw new AudioMuseAuthException(sprintf(
                    'AudioMuse %s %s returned HTTP %d: check AUDIOMUSE_API_KEY (AudioMuse API_TOKEN).',
                    $method,
                    $path,
                    $status,
                ));
            }
            if ($status >= 400) {
                $message = sprintf('AudioMuse %s %s returned HTTP %d', $method, $path, $status);
                $error = $this->errorMessage($response->getContent(false));
                if ($error !== null) {
                    $message .= ': ' . $error;
                }
                throw $status === 404 ? new AudioMuseNotFoundException($message . '.') : new AudioMuseException($message . '.');
            }

            /** @var array<mixed> $body */
            $body = $response->toArray(false);
        } catch (AudioMuseException $e) {
            throw $e;
        } catch (ExceptionInterface $e) {
            throw new AudioMuseException(sprintf('AudioMuse %s %s failed: %s', $method, $path, $e->getMessage()), 0, $e);
        }

        return $body;
    }

    /** AudioMuse error bodies carry a readable `error_message`; anything else is ignored. */
    private function errorMessage(string $body): ?string
    {
        $decoded = json_decode($body, true);
        $message = is_array($decoded) ? ($decoded['error_message'] ?? null) : null;

        return is_string($message) && trim($message) !== '' ? trim($message) : null;
    }
}
