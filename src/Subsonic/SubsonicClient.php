<?php

namespace App\Subsonic;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class SubsonicClient
{
    private const API_VERSION = '1.16.1';
    private const CLIENT_ID = 'navidrome-tools';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $baseUrl,
        private readonly string $user,
        #[\SensitiveParameter] private readonly string $password,
    ) {
    }

    /**
     * @param string[] $songIds
     *
     * @return string id of the created playlist
     */
    public function createPlaylist(string $name, array $songIds): string
    {
        $params = ['name' => $name];
        $body = [];
        foreach ($songIds as $id) {
            $body[] = 'songId=' . rawurlencode((string) $id);
        }
        $extraQuery = implode('&', $body);

        $data = $this->call('createPlaylist', $params, $extraQuery);

        $id = $data['playlist']['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new \RuntimeException('createPlaylist did not return an id. Raw response: ' . json_encode($data));
        }

        return $id;
    }

    public function deletePlaylist(string $id): void
    {
        $this->call('deletePlaylist', ['id' => $id]);
    }

    /**
     * @return array<int, array{id: string, name: string, owner: string}>
     */
    public function getPlaylists(): array
    {
        $data = $this->call('getPlaylists', []);
        $list = $data['playlists']['playlist'] ?? [];

        $out = [];
        foreach ($list as $p) {
            $out[] = [
                'id' => (string) ($p['id'] ?? ''),
                'name' => (string) ($p['name'] ?? ''),
                'owner' => (string) ($p['owner'] ?? ''),
            ];
        }

        return $out;
    }

    public function findPlaylistByName(string $name): ?string
    {
        foreach ($this->getPlaylists() as $p) {
            if ($p['owner'] === $this->user && $p['name'] === $name) {
                return $p['id'];
            }
        }

        return null;
    }

    /**
     * List every starred song for the configured Subsonic user.
     *
     * @return array<int, array{id: string, title: string, artist: string, album: string}>
     */
    public function getStarred(): array
    {
        $data = $this->call('getStarred', []);
        $songs = $data['starred']['song'] ?? [];

        $out = [];
        foreach ($songs as $s) {
            $out[] = [
                'id' => (string) ($s['id'] ?? ''),
                'title' => (string) ($s['title'] ?? ''),
                'artist' => (string) ($s['artist'] ?? ''),
                'album' => (string) ($s['album'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Star one or more songs by Subsonic media_file id. No-op when the
     * list is empty. Subsonic accepts repeated `id=` parameters in the
     * same call; we batch by 50 to avoid blowing up the URL length.
     */
    public function starTracks(string ...$songIds): void
    {
        $this->changeStar('star', $songIds);
    }

    /**
     * Unstar one or more songs by Subsonic media_file id. Same batching
     * as {@see starTracks()}.
     */
    public function unstarTracks(string ...$songIds): void
    {
        $this->changeStar('unstar', $songIds);
    }

    /**
     * @param string[] $songIds
     */
    private function changeStar(string $method, array $songIds): void
    {
        $songIds = array_values(array_filter($songIds, static fn (string $id) => $id !== ''));
        if ($songIds === []) {
            return;
        }
        foreach (array_chunk($songIds, 50) as $batch) {
            $extra = implode('&', array_map(
                static fn (string $id) => 'id=' . rawurlencode($id),
                $batch,
            ));
            $this->call($method, [], $extra);
        }
    }

    public function ping(): bool
    {
        try {
            $data = $this->call('ping', []);
            return ($data['status'] ?? null) === 'ok';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Fetch the raw cover art binary for a Navidrome id (album, artist, or
     * media_file id). Subsonic returns the image bytes directly — no JSON
     * envelope — so we can't reuse {@see call()}.
     *
     * `$size` is in pixels; clamped to [1, 1024] to dodge a known DoS on
     * unbounded resize. 0 (default) means « native size » per the spec.
     */
    public function fetchCoverArt(string $id, int $size = 0): string
    {
        if ($id === '') {
            throw new \RuntimeException('fetchCoverArt requires a non-empty id.');
        }
        if ($size < 0) {
            $size = 0;
        }
        if ($size > 1024) {
            $size = 1024;
        }

        $params = ['id' => $id];
        if ($size > 0) {
            $params['size'] = $size;
        }

        return $this->callBinary('getCoverArt', $params);
    }

    /**
     * @param array<string, scalar> $params
     */
    private function callBinary(string $method, array $params): string
    {
        $salt = bin2hex(random_bytes(8));
        $token = md5($this->password . $salt);

        $auth = [
            'u' => $this->user,
            't' => $token,
            's' => $salt,
            'v' => self::API_VERSION,
            'c' => self::CLIENT_ID,
        ];

        $url = rtrim($this->baseUrl, '/') . '/rest/' . $method . '.view?' . http_build_query(array_merge($auth, $params));

        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => 30]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                throw new \RuntimeException(sprintf('Subsonic %s returned HTTP %d', $method, $status));
            }
            $contentType = $response->getHeaders(false)['content-type'][0] ?? '';
            // Navidrome can answer with a JSON error wrapped in 200 when the
            // id is unknown. Detect and reject so the caller can serve a
            // fallback rather than write garbage to the cache.
            if (str_starts_with($contentType, 'application/json') || str_starts_with($contentType, 'text/')) {
                throw new \RuntimeException(sprintf('Subsonic %s returned non-binary payload (Content-Type: %s)', $method, $contentType));
            }

            return $response->getContent();
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('Subsonic call %s failed: %s', $method, $e->getMessage()), 0, $e);
        }
    }

    /**
     * @param array<string, scalar> $params
     *
     * @return array<string, mixed>
     */
    private function call(string $method, array $params, string $extraQuery = ''): array
    {
        $salt = bin2hex(random_bytes(8));
        $token = md5($this->password . $salt);

        $auth = [
            'u' => $this->user,
            't' => $token,
            's' => $salt,
            'v' => self::API_VERSION,
            'c' => self::CLIENT_ID,
            'f' => 'json',
        ];

        $query = http_build_query(array_merge($auth, $params));
        if ($extraQuery !== '') {
            $query .= '&' . $extraQuery;
        }

        $url = rtrim($this->baseUrl, '/') . '/rest/' . $method . '.view?' . $query;

        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => 30]);
            $body = $response->toArray(throw: true);
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf(
                'Subsonic call %s failed: %s',
                $method,
                $e->getMessage(),
            ), 0, $e);
        }

        $root = $body['subsonic-response'] ?? null;
        if (!is_array($root)) {
            throw new \RuntimeException('Invalid Subsonic response: missing root key.');
        }
        if (($root['status'] ?? '') !== 'ok') {
            $err = $root['error']['message'] ?? 'unknown error';
            throw new \RuntimeException('Subsonic error on ' . $method . ': ' . $err);
        }

        return $root;
    }
}
