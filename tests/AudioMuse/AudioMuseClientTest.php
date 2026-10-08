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

    public function testFindPathSendsParamsAndReturnsIdsInOrder(): void
    {
        $captured = '';
        $http = new MockHttpClient(function (string $method, string $url) use (&$captured): MockResponse {
            $captured = $url;

            return new MockResponse(json_encode([
                'path' => [
                    ['item_id' => 'start', 'title' => 'S', 'embedding_vector' => []],
                    ['item_id' => 'mid'],
                    ['title' => 'no id'], // skipped
                    ['item_id' => 'end'],
                ],
                'total_distance' => 1.5,
            ], \JSON_THROW_ON_ERROR));
        });

        $path = (new AudioMuseClient($http, 'http://am:8000'))->findPath('start', 'end', 25);

        $this->assertSame(['start', 'mid', 'end'], $path);
        $this->assertStringContainsString('/api/find_path', $captured);
        $this->assertStringContainsString('start_song_id=start', $captured);
        $this->assertStringContainsString('end_song_id=end', $captured);
        $this->assertStringContainsString('max_steps=25', $captured);
        $this->assertStringContainsString('path_fix_size=true', $captured);
    }

    public function testFindPathNoPathIsNotFound(): void
    {
        $http = new MockHttpClient([new MockResponse('{"error":"No path found"}', ['http_code' => 404])]);

        $this->expectException(AudioMuseNotFoundException::class);
        (new AudioMuseClient($http, 'http://am:8000'))->findPath('a', 'b', 10);
    }

    public function testTextSearchPostsQueryAndReturnsResultsInOrder(): void
    {
        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = [$method, $url, $options];

            return new MockResponse(json_encode([
                'query' => 'piano calme',
                'count' => 2,
                'results' => [
                    ['item_id' => 'mf-1', 'similarity' => 0.31, 'title' => 'A', 'top_mood' => 'relaxed'],
                    ['item_id' => 'mf-2', 'similarity' => 0.29],
                    ['title' => 'no id'], // skipped
                ],
            ], \JSON_THROW_ON_ERROR));
        });

        $results = (new AudioMuseClient($http, 'http://am:8000', 'k'))->textSearch('piano calme', 30);

        $this->assertSame([
            ['item_id' => 'mf-1', 'similarity' => 0.31],
            ['item_id' => 'mf-2', 'similarity' => 0.29],
        ], $results);
        [$method, $url, $options] = $captured;
        $this->assertSame('POST', $method);
        $this->assertStringEndsWith('/api/clap/search', $url);
        $this->assertSame(['query' => 'piano calme', 'limit' => 30], json_decode($options['body'], true));
        $this->assertContains('Authorization: Bearer k', $options['headers']);
        $this->assertSame(120.0, (float) $options['timeout']);
    }

    public function testErrorMessageFromAudioMuseIsSurfaced(): void
    {
        $http = new MockHttpClient([new MockResponse(
            '{"error_code":1001,"error_message":"Invalid request. CLAP text search is disabled."}',
            ['http_code' => 400],
        )]);

        $this->expectException(AudioMuseException::class);
        $this->expectExceptionMessage('HTTP 400: Invalid request. CLAP text search is disabled.');
        (new AudioMuseClient($http, 'http://am:8000'))->textSearch('x', 5);
    }
}
