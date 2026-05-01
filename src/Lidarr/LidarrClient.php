<?php

namespace App\Lidarr;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class LidarrClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LidarrConfig $config,
    ) {
    }

    public function ping(): bool
    {
        if (!$this->config->isConfigured()) {
            return false;
        }
        try {
            $resp = $this->request('GET', '/api/v1/system/status');

            return ($resp['version'] ?? null) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<array{
     *     foreignArtistId: string,
     *     artistName: string,
     *     overview?: string,
     *     disambiguation?: string,
     *     id?: int
     * }>
     */
    public function searchArtist(string $term): array
    {
        $resp = $this->request('GET', '/api/v1/artist/lookup', ['term' => $term]);

        $hits = [];
        foreach ($resp as $r) {
            if (!isset($r['artistName'], $r['foreignArtistId'])) {
                continue;
            }
            $hits[] = [
                'foreignArtistId' => (string) $r['foreignArtistId'],
                'artistName' => (string) $r['artistName'],
                'overview' => isset($r['overview']) ? (string) $r['overview'] : null,
                'disambiguation' => isset($r['disambiguation']) ? (string) $r['disambiguation'] : null,
                'id' => isset($r['id']) ? (int) $r['id'] : null,
            ];
        }

        return $hits;
    }

    /**
     * Add an artist to Lidarr. Pass a hit returned by searchArtist().
     *
     * @param array<string, mixed> $hit
     *
     * @return array{id: int, artistName: string, alreadyExists: bool}
     */
    public function addArtist(array $hit): array
    {
        $payload = $hit;
        $payload['qualityProfileId'] = $this->config->qualityProfileId;
        $payload['metadataProfileId'] = $this->config->metadataProfileId;
        $payload['rootFolderPath'] = $this->config->rootFolderPath;
        $payload['monitored'] = true;
        $payload['addOptions'] = [
            'monitor' => $this->config->monitor,
            'searchForMissingAlbums' => true,
        ];

        try {
            $resp = $this->request('POST', '/api/v1/artist', body: $payload);
        } catch (LidarrConflictException $e) {
            // Lidarr returns 400 with a clear message when the artist
            // already exists in the library.
            return [
                'id' => 0,
                'artistName' => (string) ($hit['artistName'] ?? ''),
                'alreadyExists' => true,
            ];
        }

        return [
            'id' => (int) ($resp['id'] ?? 0),
            'artistName' => (string) ($resp['artistName'] ?? $hit['artistName'] ?? ''),
            'alreadyExists' => false,
        ];
    }

    /**
     * @param array<string, scalar>|null $query
     * @param array<string, mixed>|null  $body
     *
     * @return array<int|string, mixed>
     */
    private function request(string $method, string $path, ?array $query = null, ?array $body = null): array
    {
        if (!$this->config->isConfigured()) {
            throw new \RuntimeException('Lidarr is not configured (LIDARR_URL / LIDARR_API_KEY).');
        }

        $url = rtrim($this->config->url, '/') . $path;
        $options = [
            'headers' => ['X-Api-Key' => $this->config->apiKey, 'Accept' => 'application/json'],
            'timeout' => 30,
        ];
        if ($query !== null) {
            $options['query'] = $query;
        }
        if ($body !== null) {
            $options['json'] = $body;
        }

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $status = $response->getStatusCode();
            if ($status >= 400) {
                $bodyText = $response->getContent(throw: false);
                if ($status === 400 && stripos($bodyText, 'already') !== false) {
                    throw new LidarrConflictException('Lidarr says: artist already exists.');
                }
                throw new \RuntimeException(sprintf('Lidarr %s %s returned %d: %s', $method, $path, $status, $bodyText));
            }
            $payload = $response->toArray(throw: true);
        } catch (LidarrConflictException) {
            throw new LidarrConflictException('Artist already exists in Lidarr.');
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('Lidarr %s %s failed: %s', $method, $path, $e->getMessage()), 0, $e);
        }

        return $payload;
    }
}
