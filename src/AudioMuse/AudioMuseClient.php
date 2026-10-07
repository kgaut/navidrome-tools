<?php

namespace App\AudioMuse;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thin client for a self-hosted AudioMuse-AI instance, which performs sonic
 * analysis of the library and can return sonically similar tracks.
 *
 * We only use the « instant mix » endpoint:
 *   GET /api/similar_tracks?item_id=…&n=… → [{item_id, …, distance}]
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
     * @param array<string, scalar> $query
     *
     * @return array<mixed>
     */
    private function get(string $path, array $query): array
    {
        $headers = ['Accept' => 'application/json'];
        if (trim($this->apiKey) !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }

        $url = rtrim($this->baseUrl, '/') . $path;

        try {
            $response = $this->httpClient->request('GET', $url, [
                'query' => $query,
                'headers' => $headers,
                'timeout' => 30,
            ]);
            $status = $response->getStatusCode();
            if ($status === 401 || $status === 403) {
                throw new AudioMuseAuthException(sprintf(
                    'AudioMuse GET %s returned HTTP %d: check AUDIOMUSE_API_KEY (AudioMuse API_TOKEN).',
                    $path,
                    $status,
                ));
            }
            if ($status === 404) {
                throw new AudioMuseNotFoundException(sprintf('AudioMuse GET %s returned HTTP 404.', $path));
            }
            if ($status >= 400) {
                throw new AudioMuseException(sprintf('AudioMuse GET %s returned HTTP %d.', $path, $status));
            }

            /** @var array<mixed> $body */
            $body = $response->toArray(false);
        } catch (AudioMuseException $e) {
            throw $e;
        } catch (ExceptionInterface $e) {
            throw new AudioMuseException(sprintf('AudioMuse GET %s failed: %s', $path, $e->getMessage()), 0, $e);
        }

        return $body;
    }
}
