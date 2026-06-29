<?php

namespace App\Tests\AudioMuse;

use App\AudioMuse\AudioMuseClient;
use App\AudioMuse\AudioMuseException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class AudioMuseClientTest extends TestCase
{
    public function testIsConfiguredRequiresBaseUrl(): void
    {
        $http = new MockHttpClient([]);
        $this->assertFalse((new AudioMuseClient($http, ''))->isConfigured());
        $this->assertTrue((new AudioMuseClient($http, 'http://am:8000'))->isConfigured());
    }

    public function testSimilarTracksThrowsWhenUnconfigured(): void
    {
        $client = new AudioMuseClient(new MockHttpClient([]), '');

        $this->expectException(AudioMuseException::class);
        $this->expectExceptionMessage('not set');
        $client->similarTracks('mf-a', 10);
    }

    public function testSimilarTracksParsesAndSendsParamsAndKey(): void
    {
        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = [$method, $url, $options];

            return new MockResponse(json_encode([
                'similar_songs' => [
                    ['item_id' => 'mf-b', 'title' => 'B', 'author' => 'X', 'distance' => 0.12],
                    ['item_id' => 'mf-c', 'distance' => 0.34],
                    ['title' => 'no id'], // skipped
                ],
            ], \JSON_THROW_ON_ERROR));
        });

        $client = new AudioMuseClient($http, 'http://am:8000/', 'secret-key');
        $similar = $client->similarTracks('mf-a', 25);

        $this->assertSame([
            ['item_id' => 'mf-b', 'distance' => 0.12],
            ['item_id' => 'mf-c', 'distance' => 0.34],
        ], $similar);

        [$method, $url, $options] = $captured;
        $this->assertSame('GET', $method);
        $this->assertStringContainsString('/api/similar_tracks', $url);
        $this->assertStringContainsString('item_id=mf-a', $url);
        $this->assertStringContainsString('n=25', $url);
        $this->assertStringContainsString('eliminate_duplicates=true', $url);
        $this->assertContains('X-API-Key: secret-key', $options['headers']);
    }

    public function testNoApiKeyHeaderWhenUnset(): void
    {
        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $options;

            return new MockResponse(json_encode(['similar_songs' => []], \JSON_THROW_ON_ERROR));
        });

        (new AudioMuseClient($http, 'http://am:8000'))->similarTracks('mf-a', 5);

        foreach ($captured['headers'] as $h) {
            $this->assertStringStartsNotWith('X-API-Key', $h);
        }
    }

    public function testHttpErrorIsWrapped(): void
    {
        $http = new MockHttpClient([new MockResponse('boom', ['http_code' => 500])]);
        $client = new AudioMuseClient($http, 'http://am:8000');

        $this->expectException(AudioMuseException::class);
        $this->expectExceptionMessage('HTTP 500');
        $client->similarTracks('mf-a', 5);
    }
}
