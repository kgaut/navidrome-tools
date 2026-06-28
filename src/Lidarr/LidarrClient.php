<?php

namespace App\Lidarr;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Minimal client for Lidarr's v1 REST API — just what the artist
 * recommendation feature needs: discover config (root folders / quality /
 * metadata profiles), list already-tracked artists for dedup, and ADD an
 * artist by its MusicBrainz id.
 *
 * Auth: `X-Api-Key` header. {@see isConfigured()} guards every write so the
 * UI / engine can degrade to the deep-link {@see \App\Twig\LidarrExtension}
 * when Lidarr isn't wired.
 *
 * Defaults for root folder / profiles come from config but are resolved
 * lazily against the live instance when left blank (first root folder, first
 * profile) so a minimal `LIDARR_URL` + `LIDARR_API_KEY` is enough to start.
 */
class LidarrClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl = '',
        #[\SensitiveParameter] private readonly string $apiKey = '',
        private readonly string $rootFolder = '',
        private readonly int $qualityProfileId = 0,
        private readonly int $metadataProfileId = 0,
        private readonly string $monitor = 'all',
        private readonly bool $searchOnAdd = true,
    ) {
    }

    public function isConfigured(): bool
    {
        return trim($this->baseUrl) !== '' && trim($this->apiKey) !== '';
    }

    public function ping(): bool
    {
        try {
            $this->get('/api/v1/system/status');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<array{id: int, path: string, accessible: bool, freeSpace: int}>
     */
    public function getRootFolders(): array
    {
        $out = [];
        foreach ($this->get('/api/v1/rootfolder') as $r) {
            if (!is_array($r)) {
                continue;
            }
            $out[] = [
                'id' => (int) ($r['id'] ?? 0),
                'path' => (string) ($r['path'] ?? ''),
                'accessible' => (bool) ($r['accessible'] ?? false),
                'freeSpace' => (int) ($r['freeSpace'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function getQualityProfiles(): array
    {
        return $this->namedIdList('/api/v1/qualityprofile');
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function getMetadataProfiles(): array
    {
        return $this->namedIdList('/api/v1/metadataprofile');
    }

    /**
     * MusicBrainz artist ids already tracked by Lidarr, as `[mbid => true]`
     * for O(1) dedup before recommending / adding.
     *
     * @return array<string, true>
     */
    public function existingArtistMbids(): array
    {
        $out = [];
        foreach ($this->get('/api/v1/artist') as $a) {
            if (is_array($a) && isset($a['foreignArtistId']) && is_string($a['foreignArtistId']) && $a['foreignArtistId'] !== '') {
                $out[$a['foreignArtistId']] = true;
            }
        }

        return $out;
    }

    /**
     * Add an artist to Lidarr by its MusicBrainz id. Looks the artist up so
     * Lidarr fills the canonical metadata, then POSTs it with our monitor /
     * profile / root-folder choices. Returns the created artist resource.
     *
     * @return array<string, mixed>
     */
    public function addArtist(string $mbid): array
    {
        if (!$this->isConfigured()) {
            throw new LidarrException('Lidarr is not configured (LIDARR_URL / LIDARR_API_KEY).');
        }
        $mbid = trim($mbid);
        if ($mbid === '') {
            throw new LidarrException('addArtist requires a non-empty MusicBrainz id.');
        }

        $lookup = $this->get('/api/v1/artist/lookup', ['term' => 'lidarr:' . $mbid]);
        $artist = $lookup[0] ?? null;
        if (!is_array($artist)) {
            throw new LidarrException(sprintf('Lidarr found no artist for MBID %s.', $mbid));
        }

        $artist['qualityProfileId'] = $this->resolveQualityProfileId();
        $artist['metadataProfileId'] = $this->resolveMetadataProfileId();
        $artist['rootFolderPath'] = $this->resolveRootFolder();
        $artist['monitored'] = true;
        $artist['monitorNewItems'] = 'all';
        $artist['addOptions'] = [
            'monitor' => $this->monitor,
            'searchForMissingAlbums' => $this->searchOnAdd,
        ];

        return $this->post('/api/v1/artist', $artist);
    }

    // --- internals -------------------------------------------------------

    private function resolveRootFolder(): string
    {
        if (trim($this->rootFolder) !== '') {
            return $this->rootFolder;
        }
        $folders = $this->getRootFolders();
        if ($folders === []) {
            throw new LidarrException('No Lidarr root folder configured (set LIDARR_ROOT_FOLDER).');
        }

        return $folders[0]['path'];
    }

    private function resolveQualityProfileId(): int
    {
        if ($this->qualityProfileId > 0) {
            return $this->qualityProfileId;
        }
        $profiles = $this->getQualityProfiles();
        if ($profiles === []) {
            throw new LidarrException('No Lidarr quality profile found.');
        }

        return $profiles[0]['id'];
    }

    private function resolveMetadataProfileId(): int
    {
        if ($this->metadataProfileId > 0) {
            return $this->metadataProfileId;
        }
        $profiles = $this->getMetadataProfiles();
        if ($profiles === []) {
            throw new LidarrException('No Lidarr metadata profile found.');
        }

        return $profiles[0]['id'];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function namedIdList(string $path): array
    {
        $out = [];
        foreach ($this->get($path) as $r) {
            if (is_array($r)) {
                $out[] = ['id' => (int) ($r['id'] ?? 0), 'name' => (string) ($r['name'] ?? '')];
            }
        }

        return $out;
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return list<mixed>|array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, ['query' => $query]);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        /** @var array<string, mixed> $res */
        $res = $this->request('POST', $path, ['json' => $body]);

        return $res;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<int|string, mixed>
     */
    private function request(string $method, string $path, array $options): array
    {
        if (trim($this->baseUrl) === '') {
            throw new LidarrException('Lidarr base URL is not set (LIDARR_URL).');
        }

        $url = rtrim($this->baseUrl, '/') . $path;
        $options['headers'] = ['X-Api-Key' => $this->apiKey, 'Accept' => 'application/json'];
        $options['timeout'] = 30;

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $status = $response->getStatusCode();
        } catch (ExceptionInterface $e) {
            throw new LidarrException(sprintf('Lidarr %s %s failed: %s', $method, $path, $e->getMessage()), 0, $e);
        }

        if ($status >= 400) {
            throw new LidarrException(sprintf('Lidarr %s %s returned HTTP %d.', $method, $path, $status));
        }

        try {
            /** @var array<int|string, mixed> $body */
            $body = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new LidarrException(sprintf('Lidarr %s %s returned invalid JSON: %s', $method, $path, $e->getMessage()), 0, $e);
        }

        return $body;
    }
}
