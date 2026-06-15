<?php

namespace App\Subsonic;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Minimal read-only client for Navidrome's Subsonic-compatible REST API.
 *
 * Scope of this first slice: just what the /playlists pages need (ping,
 * getPlaylists, getPlaylist). The POC carried 15+ methods (createPlaylist,
 * updatePlaylist, star/unstar, search3, fetchCoverArt, startScan…) but we
 * port them only when the corresponding feature ships, to keep this PR
 * reviewable.
 *
 * Auth follows the « salted token » scheme of Subsonic ≥ 1.13.0 :
 * `t = md5(password + salt)` is sent in the URL instead of the raw
 * password. Navidrome accepts this since its very first releases.
 */
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
     * @return list<array{
     *     id: string, name: string, owner: string,
     *     songCount: int, duration: int, public: bool,
     *     created: ?string, changed: ?string, comment: string
     * }>
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
                'songCount' => (int) ($p['songCount'] ?? 0),
                'duration' => (int) ($p['duration'] ?? 0),
                'public' => (bool) ($p['public'] ?? false),
                'created' => isset($p['created']) ? (string) $p['created'] : null,
                'changed' => isset($p['changed']) ? (string) $p['changed'] : null,
                'comment' => (string) ($p['comment'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Fetch one playlist with its tracks. Throws when the id is unknown
     * — bubbles up to the controller as a 404 via the catch path there.
     *
     * @return array{
     *     id: string, name: string, owner: string,
     *     songCount: int, duration: int, public: bool,
     *     created: ?string, changed: ?string, comment: string,
     *     tracks: list<array{
     *         id: string, title: string, artist: string, album: string,
     *         duration: int, playCount: int, year: ?int, starred: ?string
     *     }>
     * }
     */
    public function getPlaylist(string $id): array
    {
        if ($id === '') {
            throw new \RuntimeException('getPlaylist requires a non-empty id.');
        }

        $data = $this->call('getPlaylist', ['id' => $id]);
        $p = $data['playlist'] ?? null;
        if (!is_array($p)) {
            throw new \RuntimeException('getPlaylist did not return a playlist node. Raw response: ' . json_encode($data));
        }

        $tracks = [];
        foreach (($p['entry'] ?? []) as $e) {
            $tracks[] = [
                'id' => (string) ($e['id'] ?? ''),
                'title' => (string) ($e['title'] ?? ''),
                'artist' => (string) ($e['artist'] ?? ''),
                'album' => (string) ($e['album'] ?? ''),
                'duration' => (int) ($e['duration'] ?? 0),
                'playCount' => (int) ($e['playCount'] ?? 0),
                'year' => isset($e['year']) ? (int) $e['year'] : null,
                'starred' => isset($e['starred']) ? (string) $e['starred'] : null,
            ];
        }

        return [
            'id' => (string) ($p['id'] ?? $id),
            'name' => (string) ($p['name'] ?? ''),
            'owner' => (string) ($p['owner'] ?? ''),
            'songCount' => (int) ($p['songCount'] ?? count($tracks)),
            'duration' => (int) ($p['duration'] ?? 0),
            'public' => (bool) ($p['public'] ?? false),
            'created' => isset($p['created']) ? (string) $p['created'] : null,
            'changed' => isset($p['changed']) ? (string) $p['changed'] : null,
            'comment' => (string) ($p['comment'] ?? ''),
            'tracks' => $tracks,
        ];
    }

    /**
     * @param array<string, scalar> $params
     *
     * @return array<string, mixed>
     */
    private function call(string $method, array $params): array
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

        $url = rtrim($this->baseUrl, '/') . '/rest/' . $method . '.view?'
            . http_build_query(array_merge($auth, $params));

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
