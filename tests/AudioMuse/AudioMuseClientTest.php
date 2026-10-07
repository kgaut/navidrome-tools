<?php

namespace App\Tests\AudioMuse;

use App\AudioMuse\AudioMuseAuthException;
use App\AudioMuse\AudioMuseClient;
use App\AudioMuse\AudioMuseException;
use App\AudioMuse\AudioMuseNotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
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

            // AudioMuse ≥ 3.6: the list sits at the JSON root.
            return new MockResponse(json_encode([
                ['item_id' => 'mf-b', 'title' => 'B', 'author' => 'X', 'distance' => 0.12, 'top_mood' => 'happy'],
                ['item_id' => 'mf-c', 'distance' => 0.34],
                ['title' => 'no id'], // skipped
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
        $this->assertContains('Authorization: Bearer secret-key', $options['headers']);
        foreach ($options['headers'] as $h) {
            $this->assertStringStartsNotWith('X-API-Key', $h);
        }
    }

    public function testSimilarTracksStillReadsLegacyWrappedShape(): void
    {
        $http = new MockHttpClient([new MockResponse(json_encode([
            'similar_songs' => [['item_id' => 'mf-b', 'distance' => 0.12]],
        ], \JSON_THROW_ON_ERROR))]);

        $this->assertSame(
            [['item_id' => 'mf-b', 'distance' => 0.12]],
            (new AudioMuseClient($http, 'http://am:8000'))->similarTracks('mf-a', 5),
        );
    }

    public function testSimilarTracksEmptyListAtRoot(): void
    {
        $http = new MockHttpClient([new MockResponse('[]')]);

        $this->assertSame([], (new AudioMuseClient($http, 'http://am:8000'))->similarTracks('mf-a', 5));
    }

    public function testNotFoundRaisesDedicatedException(): void
    {
        $http = new MockHttpClient([new MockResponse('{"error_code":1004}', ['http_code' => 404])]);

        $this->expectException(AudioMuseNotFoundException::class);
        (new AudioMuseClient($http, 'http://am:8000'))->similarTracks('mf-a', 5);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function authStatuses(): iterable
    {
        yield '401' => [401];
        yield '403' => [403];
    }

    #[DataProvider('authStatuses')]
    public function testAuthFailureRaisesDedicatedException(int $status): void
    {
        $http = new MockHttpClient([new MockResponse('{}', ['http_code' => $status])]);

        $this->expectException(AudioMuseAuthException::class);
        $this->expectExceptionMessage('AUDIOMUSE_API_KEY');
        (new AudioMuseClient($http, 'http://am:8000', 'bad'))->similarTracks('mf-a', 5);
    }

    public function testNoApiKeyHeaderWhenUnset(): void
    {
        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $options;

            return new MockResponse('[]');
        });

        (new AudioMuseClient($http, 'http://am:8000'))->similarTracks('mf-a', 5);

        foreach ($captured['headers'] as $h) {
            $this->assertStringStartsNotWith('Authorization', $h);
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
