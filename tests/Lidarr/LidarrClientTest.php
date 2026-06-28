<?php

namespace App\Tests\Lidarr;

use App\Lidarr\LidarrClient;
use App\Lidarr\LidarrException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class LidarrClientTest extends TestCase
{
    public function testIsConfiguredRequiresUrlAndKey(): void
    {
        $http = new MockHttpClient([]);
        $this->assertFalse((new LidarrClient($http, '', ''))->isConfigured());
        $this->assertFalse((new LidarrClient($http, 'http://l:8686', ''))->isConfigured());
        $this->assertTrue((new LidarrClient($http, 'http://l:8686', 'key'))->isConfigured());
    }

    public function testExistingArtistMbidsCollectsForeignIds(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode([
                ['foreignArtistId' => 'mbid-a', 'artistName' => 'A'],
                ['foreignArtistId' => 'mbid-b', 'artistName' => 'B'],
                ['artistName' => 'No id'],
            ], \JSON_THROW_ON_ERROR)),
        ]);
        $client = new LidarrClient($http, 'http://l:8686', 'key');

        $mbids = $client->existingArtistMbids();

        $this->assertArrayHasKey('mbid-a', $mbids);
        $this->assertArrayHasKey('mbid-b', $mbids);
        $this->assertCount(2, $mbids);
    }

    public function testAddArtistLooksUpThenPostsWithProfilesAndFolder(): void
    {
        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured[] = [$method, $url, $options];
            // 1st call: lookup → return a candidate; 2nd: POST → echo created.
            if (str_contains($url, '/artist/lookup')) {
                return new MockResponse(json_encode([[
                    'foreignArtistId' => 'mbid-x', 'artistName' => 'New Artist',
                ]], \JSON_THROW_ON_ERROR));
            }

            return new MockResponse(json_encode(['id' => 7, 'artistName' => 'New Artist'], \JSON_THROW_ON_ERROR));
        });

        $client = new LidarrClient(
            $http,
            'http://l:8686',
            'secret-key',
            rootFolder: '/music',
            qualityProfileId: 3,
            metadataProfileId: 2,
            monitor: 'all',
            searchOnAdd: true,
        );

        $created = $client->addArtist('mbid-x');

        $this->assertSame(7, $created['id']);
        // Lookup carried the lidarr:{mbid} term and the api key header.
        [$m0, $u0, $o0] = $captured[0];
        $this->assertSame('GET', $m0);
        $this->assertStringContainsString('/api/v1/artist/lookup', $u0);
        $this->assertStringContainsString('term=lidarr', $u0);
        $this->assertStringContainsString('mbid-x', $u0);
        $this->assertContains('X-Api-Key: secret-key', $o0['headers']);
        // POST body merged our profile/folder/monitor choices.
        [$m1, $u1, $o1] = $captured[1];
        $this->assertSame('POST', $m1);
        $this->assertStringEndsWith('/api/v1/artist', $u1);
        $body = json_decode($o1['body'], true);
        $this->assertSame(3, $body['qualityProfileId']);
        $this->assertSame(2, $body['metadataProfileId']);
        $this->assertSame('/music', $body['rootFolderPath']);
        $this->assertTrue($body['monitored']);
        $this->assertSame('all', $body['addOptions']['monitor']);
        $this->assertTrue($body['addOptions']['searchForMissingAlbums']);
    }

    public function testAddArtistResolvesRootFolderAndProfilesWhenBlank(): void
    {
        $http = new MockHttpClient(function (string $method, string $url): MockResponse {
            if (str_contains($url, '/artist/lookup')) {
                return new MockResponse(json_encode([['foreignArtistId' => 'mbid-x', 'artistName' => 'X']], \JSON_THROW_ON_ERROR));
            }
            if (str_contains($url, '/rootfolder')) {
                return new MockResponse(json_encode([['id' => 1, 'path' => '/data/music', 'accessible' => true, 'freeSpace' => 10]], \JSON_THROW_ON_ERROR));
            }
            if (str_contains($url, '/qualityprofile')) {
                return new MockResponse(json_encode([['id' => 9, 'name' => 'Lossless']], \JSON_THROW_ON_ERROR));
            }
            if (str_contains($url, '/metadataprofile')) {
                return new MockResponse(json_encode([['id' => 4, 'name' => 'Standard']], \JSON_THROW_ON_ERROR));
            }

            // POST /artist
            return new MockResponse(json_encode(['id' => 1], \JSON_THROW_ON_ERROR));
        });

        // Blank root folder + profile ids → resolved from the live instance.
        $client = new LidarrClient($http, 'http://l:8686', 'key');
        $created = $client->addArtist('mbid-x');

        $this->assertSame(1, $created['id']);
    }

    public function testAddArtistThrowsWhenNotConfigured(): void
    {
        $client = new LidarrClient(new MockHttpClient([]), '', '');

        $this->expectException(LidarrException::class);
        $this->expectExceptionMessage('not configured');
        $client->addArtist('mbid-x');
    }

    public function testHttpErrorIsWrapped(): void
    {
        $http = new MockHttpClient([new MockResponse('nope', ['http_code' => 401])]);
        $client = new LidarrClient($http, 'http://l:8686', 'bad-key');

        $this->expectException(LidarrException::class);
        $this->expectExceptionMessage('HTTP 401');
        $client->existingArtistMbids();
    }

    public function testPingReturnsFalseOnError(): void
    {
        $http = new MockHttpClient([new MockResponse('err', ['http_code' => 500])]);
        $this->assertFalse((new LidarrClient($http, 'http://l:8686', 'key'))->ping());
    }
}
