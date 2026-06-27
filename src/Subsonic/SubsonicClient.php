<?php

namespace App\Subsonic;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Minimal read-only client for Navidrome's Subsonic-compatible REST API.
 *
 * Scope so far: the /playlists pages (ping, getPlaylists, getPlaylist) plus
 * the write path the playlist generator needs (createPlaylist,
 * replacePlaylist, findPlaylistByName). The POC carried more (star/unstar,
 * search3, fetchCoverArt, startScan…) but we port them only when the
 * corresponding feature ships, to keep each PR reviewable.
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
     * Create a new playlist from an ordered list of song ids (Navidrome
     * media_file ids). Returns the id of the created playlist. Subsonic
     * `createPlaylist.view?name=…&songId=a&songId=b` builds the playlist
     * in the given order.
     *
     * @param list<string> $songIds
     */
    public function createPlaylist(string $name, array $songIds): string
    {
        if ($name === '') {
            throw new \RuntimeException('createPlaylist requires a non-empty name.');
        }

        $data = $this->call('createPlaylist', ['name' => $name], ['songId' => array_values($songIds)]);

        // Navidrome echoes the created playlist node; fall back to a
        // re-lookup by name if some server variant omits it.
        $id = (string) ($data['playlist']['id'] ?? '');
        if ($id !== '') {
            return $id;
        }
        $existing = $this->findPlaylistByName($name);

        return $existing['id'] ?? throw new \RuntimeException(
            'createPlaylist did not return an id and the playlist could not be found by name: ' . $name,
        );
    }

    /**
     * Overwrite an existing playlist's song list in place (idempotent
     * regeneration — no duplicate playlist is created). Subsonic
     * `createPlaylist.view?playlistId=…&songId=…` replaces the whole
     * contents with the given ordered list. `name` is passed through so a
     * server that honours it keeps the title in sync.
     *
     * @param list<string> $songIds
     */
    public function replacePlaylist(string $playlistId, string $name, array $songIds): void
    {
        if ($playlistId === '') {
            throw new \RuntimeException('replacePlaylist requires a non-empty playlistId.');
        }

        $this->call(
            'createPlaylist',
            ['playlistId' => $playlistId, 'name' => $name],
            ['songId' => array_values($songIds)],
        );
    }

    /**
     * Find one playlist owned by the configured user whose name matches
     * exactly (case-sensitive, as Navidrome stores it). Returns the
     * normalized {@see getPlaylists()} row or null. Used by the generator
     * to decide create-vs-replace.
     *
     * @return array{
     *     id: string, name: string, owner: string,
     *     songCount: int, duration: int, public: bool,
     *     created: ?string, changed: ?string, comment: string
     * }|null
     */
    public function findPlaylistByName(string $name): ?array
    {
        foreach ($this->getPlaylists() as $p) {
            if ($p['name'] === $name && $p['owner'] === $this->user) {
                return $p;
            }
        }

        return null;
    }

    /**
     * @param array<string, scalar>     $params   single-valued query params
     * @param array<string, list<string>> $repeated repeated-key params (e.g.
     *        `['songId' => ['a', 'b']]` → `songId=a&songId=b`). `http_build_query`
     *        would render these as `songId[0]=…`, which Subsonic rejects, so we
     *        append them manually.
     *
     * @return array<string, mixed>
     */
    private function call(string $method, array $params, array $repeated = []): array
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
        foreach ($repeated as $key => $values) {
            foreach ($values as $value) {
                $query .= '&' . rawurlencode($key) . '=' . rawurlencode($value);
            }
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
