<?php

namespace App\Subsonic;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class SubsonicClient
{
    private const API_VERSION = '1.16.1';
    private const CLIENT_ID = 'navidrome-playlist-generator';

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
