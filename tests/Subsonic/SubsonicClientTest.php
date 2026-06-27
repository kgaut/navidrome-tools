<?php

namespace App\Tests\Subsonic;

use App\Subsonic\SubsonicClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class SubsonicClientTest extends TestCase
{
    public function testPingReturnsTrueOnOkStatus(): void
    {
        $payload = $this->envelope(['status' => 'ok']);
        $http = new MockHttpClient([new MockResponse($payload)]);
        $client = new SubsonicClient($http, 'http://nd:4533', 'admin', 'pwd');

        $this->assertTrue($client->ping());
    }

    public function testPingReturnsFalseOnHttpFailure(): void
    {
        // Subsonic responses live behind random_bytes() salt — we don't
        // need to control the URL, just that exceptions become `false`.
        $http = new MockHttpClient([new MockResponse('boom', ['http_code' => 500])]);
        $client = new SubsonicClient($http, 'http://nd:4533', 'admin', 'pwd');

        $this->assertFalse($client->ping());
    }

    public function testSignedUrlCarriesSaltedToken(): void
    {
        // Regression: the auth scheme must send `t=md5(password+salt)`
        // and a per-call random salt, never the raw password.
        $payload = $this->envelope(['status' => 'ok']);
        $captured = null;
        $http = new MockHttpClient(function (string $method, string $url) use (&$captured, $payload): MockResponse {
            $captured = $url;

            return new MockResponse($payload);
        });

        $client = new SubsonicClient($http, 'http://nd:4533', 'admin', 'super-secret');
        $client->ping();

        $this->assertIsString($captured);
        $this->assertStringStartsWith('http://nd:4533/rest/ping.view?', $captured);
        parse_str(parse_url($captured, \PHP_URL_QUERY) ?: '', $q);
        $this->assertSame('admin', $q['u'] ?? null);
        $this->assertSame('json', $q['f'] ?? null);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) ($q['s'] ?? ''));
        $this->assertSame(md5('super-secret' . $q['s']), $q['t'] ?? null);
        $this->assertStringNotContainsString('super-secret', $captured);
    }

    public function testGetPlaylistsParsesNormalizedList(): void
    {
        $payload = $this->envelope([
            'status' => 'ok',
            'playlists' => [
                'playlist' => [
                    [
                        'id' => 'pl-1', 'name' => 'Morning', 'owner' => 'admin',
                        'songCount' => 12, 'duration' => 2400, 'public' => true,
                        'created' => '2025-01-01T10:00:00.000Z',
                        'changed' => '2025-06-14T12:00:00.000Z',
                        'comment' => 'Wake up',
                    ],
                    [
                        // Missing-field tolerance : only `id` provided.
                        'id' => 'pl-2',
                    ],
                ],
            ],
        ]);
        $http = new MockHttpClient([new MockResponse($payload)]);
        $client = new SubsonicClient($http, 'http://nd:4533', 'admin', 'pwd');

        $rows = $client->getPlaylists();

        $this->assertCount(2, $rows);
        $this->assertSame('pl-1', $rows[0]['id']);
        $this->assertSame('Morning', $rows[0]['name']);
        $this->assertSame(12, $rows[0]['songCount']);
        $this->assertSame(2400, $rows[0]['duration']);
        $this->assertTrue($rows[0]['public']);
        $this->assertSame('Wake up', $rows[0]['comment']);

        // The fallback shape must keep types stable so the template
        // can render the row without null checks everywhere.
        $this->assertSame('pl-2', $rows[1]['id']);
        $this->assertSame('', $rows[1]['name']);
        $this->assertSame(0, $rows[1]['songCount']);
        $this->assertFalse($rows[1]['public']);
        $this->assertNull($rows[1]['created']);
    }

    public function testGetPlaylistsReturnsEmptyArrayWhenServerHasNone(): void
    {
        $payload = $this->envelope(['status' => 'ok', 'playlists' => new \stdClass()]);
        $http = new MockHttpClient([new MockResponse($payload)]);
        $client = new SubsonicClient($http, 'http://nd:4533', 'admin', 'pwd');

        $this->assertSame([], $client->getPlaylists());
    }

    public function testGetPlaylistParsesTracksAndPlaylistMetadata(): void
    {
        $payload = $this->envelope([
            'status' => 'ok',
            'playlist' => [
                'id' => 'pl-1',
                'name' => 'Best of Daft Punk',
                'owner' => 'admin',
                'songCount' => 2,
                'duration' => 600,
                'public' => false,
                'created' => '2025-01-01T10:00:00.000Z',
                'changed' => '2025-06-14T12:00:00.000Z',
                'comment' => '',
                'entry' => [
                    [
                        'id' => 'mf-1', 'title' => 'Get Lucky', 'artist' => 'Daft Punk',
                        'album' => 'Random Access Memories', 'duration' => 248,
                        'playCount' => 42, 'year' => 2013,
                        'starred' => '2025-06-14T12:00:00.000Z',
                    ],
                    [
                        // Missing-field tolerance : only `id` provided.
                        'id' => 'mf-2',
                    ],
                ],
            ],
        ]);
        $http = new MockHttpClient([new MockResponse($payload)]);
        $client = new SubsonicClient($http, 'http://nd:4533', 'admin', 'pwd');

        $pl = $client->getPlaylist('pl-1');

        $this->assertSame('Best of Daft Punk', $pl['name']);
        $this->assertSame(2, $pl['songCount']);
        $this->assertCount(2, $pl['tracks']);

        $this->assertSame('Get Lucky', $pl['tracks'][0]['title']);
        $this->assertSame(2013, $pl['tracks'][0]['year']);
        $this->assertSame(42, $pl['tracks'][0]['playCount']);
        $this->assertSame('2025-06-14T12:00:00.000Z', $pl['tracks'][0]['starred']);

        $this->assertSame('mf-2', $pl['tracks'][1]['id']);
        $this->assertSame('', $pl['tracks'][1]['title']);
        $this->assertNull($pl['tracks'][1]['year']);
        $this->assertNull($pl['tracks'][1]['starred']);
    }

    public function testGetPlaylistRejectsEmptyId(): void
    {
        $http = new MockHttpClient([]);
        $client = new SubsonicClient($http, 'http://nd:4533', 'admin', 'pwd');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('non-empty id');
        $client->getPlaylist('');

        $this->assertSame(0, $http->getRequestsCount());
    }

    public function testSubsonicErrorStatusIsBubbled(): void
    {
        $payload = $this->envelope([
            'status' => 'failed',
            'error' => ['code' => 70, 'message' => 'Playlist not found.'],
        ]);
        $http = new MockHttpClient([new MockResponse($payload)]);
        $client = new SubsonicClient($http, 'http://nd:4533', 'admin', 'pwd');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Playlist not found');
        $client->getPlaylist('unknown');
    }

    public function testBaseUrlTrailingSlashIsToleratedExactlyOnce(): void
    {
        // Regression: `http://nd:4533/` + `/rest/...` used to produce
        // `//rest/...` and break Navidrome's route matcher.
        $captured = null;
        $http = new MockHttpClient(function (string $method, string $url) use (&$captured): MockResponse {
            $captured = $url;

            return new MockResponse($this->envelope(['status' => 'ok']));
        });

        $client = new SubsonicClient($http, 'http://nd:4533/', 'admin', 'pwd');
        $client->ping();

        $this->assertIsString($captured);
        $this->assertStringStartsWith('http://nd:4533/rest/ping.view?', $captured);
        $this->assertStringNotContainsString('//rest/', $captured);
    }

    public function testCreatePlaylistSendsRepeatedSongIdParamsAndReturnsId(): void
    {
        $captured = null;
        $http = new MockHttpClient(function (string $method, string $url) use (&$captured): MockResponse {
            $captured = $url;

            return new MockResponse($this->envelope([
                'status' => 'ok',
                'playlist' => ['id' => 'pl-new', 'name' => 'Retour en arrière'],
            ]));
        });
        $client = new SubsonicClient($http, 'http://nd:4533', 'admin', 'pwd');

        $id = $client->createPlaylist('Retour en arrière', ['mf-1', 'mf-2', 'mf-3']);

        $this->assertSame('pl-new', $id);
        $this->assertIsString($captured);
        // Repeated key form — NOT songId[0]=… which Subsonic rejects.
        $this->assertStringContainsString('songId=mf-1', $captured);
        $this->assertStringContainsString('songId=mf-2', $captured);
        $this->assertStringContainsString('songId=mf-3', $captured);
        $this->assertStringNotContainsString('songId%5B', $captured); // no songId[0]
        $this->assertStringContainsString('createPlaylist.view?', $captured);
    }

    public function testCreatePlaylistRejectsEmptyName(): void
    {
        $http = new MockHttpClient([]);
        $client = new SubsonicClient($http, 'http://nd:4533', 'admin', 'pwd');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('non-empty name');
        $client->createPlaylist('', ['mf-1']);
    }

    public function testReplacePlaylistSendsPlaylistIdAndSongs(): void
    {
        $captured = null;
        $http = new MockHttpClient(function (string $method, string $url) use (&$captured): MockResponse {
            $captured = $url;

            return new MockResponse($this->envelope(['status' => 'ok']));
        });
        $client = new SubsonicClient($http, 'http://nd:4533', 'admin', 'pwd');

        $client->replacePlaylist('pl-1', 'Retour en arrière', ['mf-9', 'mf-8']);

        $this->assertIsString($captured);
        $this->assertStringContainsString('playlistId=pl-1', $captured);
        $this->assertStringContainsString('songId=mf-9', $captured);
        $this->assertStringContainsString('songId=mf-8', $captured);
    }

    public function testReplacePlaylistRejectsEmptyId(): void
    {
        $http = new MockHttpClient([]);
        $client = new SubsonicClient($http, 'http://nd:4533', 'admin', 'pwd');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('non-empty playlistId');
        $client->replacePlaylist('', 'X', ['mf-1']);
    }

    public function testFindPlaylistByNameMatchesNameAndOwner(): void
    {
        $payload = $this->envelope([
            'status' => 'ok',
            'playlists' => [
                'playlist' => [
                    ['id' => 'pl-1', 'name' => 'Retour en arrière', 'owner' => 'someone-else'],
                    ['id' => 'pl-2', 'name' => 'Retour en arrière', 'owner' => 'admin'],
                    ['id' => 'pl-3', 'name' => 'Autre', 'owner' => 'admin'],
                ],
            ],
        ]);
        $http = new MockHttpClient([new MockResponse($payload)]);
        $client = new SubsonicClient($http, 'http://nd:4533', 'admin', 'pwd');

        $found = $client->findPlaylistByName('Retour en arrière');

        $this->assertNotNull($found);
        // Must pick the one owned by the configured user, not the homonym.
        $this->assertSame('pl-2', $found['id']);
    }

    public function testFindPlaylistByNameReturnsNullWhenAbsent(): void
    {
        $payload = $this->envelope([
            'status' => 'ok',
            'playlists' => ['playlist' => [['id' => 'pl-1', 'name' => 'Autre', 'owner' => 'admin']]],
        ]);
        $http = new MockHttpClient([new MockResponse($payload)]);
        $client = new SubsonicClient($http, 'http://nd:4533', 'admin', 'pwd');

        $this->assertNull($client->findPlaylistByName('Inexistante'));
    }

    /**
     * @param array<string, mixed> $response
     */
    private function envelope(array $response): string
    {
        return json_encode(['subsonic-response' => $response], \JSON_THROW_ON_ERROR);
    }
}
