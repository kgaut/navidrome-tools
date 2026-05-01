<?php

namespace App\Tests\Lidarr;

use App\Lidarr\LidarrClient;
use App\Lidarr\LidarrConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class LidarrClientTest extends TestCase
{
    public function testPingReturnsTrueOnValidStatus(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode(['version' => '2.7.0.4480']) ?: '', [
                'response_headers' => ['content-type: application/json'],
            ]),
        ]);

        $client = new LidarrClient($http, $this->config());
        $this->assertTrue($client->ping());
    }

    public function testPingReturnsFalseWhenNotConfigured(): void
    {
        $client = new LidarrClient(new MockHttpClient([]), $this->config(url: '', apiKey: ''));
        $this->assertFalse($client->ping());
    }

    public function testSearchArtistMapsHits(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode([
                ['foreignArtistId' => 'mb-1', 'artistName' => 'Daft Punk', 'overview' => 'French duo'],
                ['foreignArtistId' => 'mb-2', 'artistName' => 'Daft Punk Tribute'],
                ['noName' => true],
            ]) ?: '', ['response_headers' => ['content-type: application/json']]),
        ]);

        $hits = (new LidarrClient($http, $this->config()))->searchArtist('daft punk');

        $this->assertCount(2, $hits);
        $this->assertSame('mb-1', $hits[0]['foreignArtistId']);
        $this->assertSame('Daft Punk', $hits[0]['artistName']);
        $this->assertSame('French duo', $hits[0]['overview']);
    }

    public function testAddArtistEnrichesPayloadAndReturnsId(): void
    {
        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured) {
            $captured = [
                'method' => $method,
                'url' => $url,
                'body' => json_decode($options['body'] ?? '{}', true),
                'headers' => $options['headers'] ?? [],
            ];

            return new MockResponse(json_encode([
                'id' => 42,
                'artistName' => 'Daft Punk',
            ]) ?: '', ['http_code' => 201, 'response_headers' => ['content-type: application/json']]);
        });

        $client = new LidarrClient($http, $this->config());
        $result = $client->addArtist([
            'foreignArtistId' => 'mb-1',
            'artistName' => 'Daft Punk',
        ]);

        $this->assertSame(42, $result['id']);
        $this->assertSame('Daft Punk', $result['artistName']);
        $this->assertFalse($result['alreadyExists']);

        $this->assertSame('POST', $captured['method']);
        $this->assertStringEndsWith('/api/v1/artist', $captured['url']);
        $this->assertSame(7, $captured['body']['qualityProfileId']);
        $this->assertSame(8, $captured['body']['metadataProfileId']);
        $this->assertSame('/music', $captured['body']['rootFolderPath']);
        $this->assertTrue($captured['body']['monitored']);
        $this->assertSame('all', $captured['body']['addOptions']['monitor']);
        $this->assertTrue($captured['body']['addOptions']['searchForMissingAlbums']);
    }

    public function testAddArtistHandlesAlreadyExistsConflict(): void
    {
        $http = new MockHttpClient([
            new MockResponse(
                json_encode(['errorMessage' => 'This artist has already been added.']) ?: '',
                ['http_code' => 400, 'response_headers' => ['content-type: application/json']],
            ),
        ]);

        $client = new LidarrClient($http, $this->config());
        $result = $client->addArtist(['foreignArtistId' => 'mb-1', 'artistName' => 'Daft Punk']);

        $this->assertTrue($result['alreadyExists']);
        $this->assertSame('Daft Punk', $result['artistName']);
    }

    private function config(string $url = 'http://lidarr:8686', string $apiKey = 'k'): LidarrConfig
    {
        return new LidarrConfig(
            url: $url,
            apiKey: $apiKey,
            rootFolderPath: '/music',
            qualityProfileId: 7,
            metadataProfileId: 8,
            monitor: 'all',
        );
    }
}
