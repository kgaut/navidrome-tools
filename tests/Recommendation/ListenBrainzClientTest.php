<?php

namespace App\Tests\Recommendation;

use App\Recommendation\ListenBrainzClient;
use App\Recommendation\ListenBrainzException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ListenBrainzClientTest extends TestCase
{
    public function testIsConfiguredRequiresUser(): void
    {
        $http = new MockHttpClient([]);
        $this->assertFalse((new ListenBrainzClient($http, ''))->isConfigured());
        $this->assertTrue((new ListenBrainzClient($http, 'alice'))->isConfigured());
    }

    public function testRecommendedRecordingsReturnsEmptyWhenUnconfigured(): void
    {
        $client = new ListenBrainzClient(new MockHttpClient([]), '');
        $this->assertSame([], $client->recommendedRecordings(10));
    }

    public function testRecommendedRecordingsParsesPayloadAndSendsToken(): void
    {
        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = [$method, $url, $options];

            return new MockResponse(json_encode([
                'payload' => [
                    'mbids' => [
                        ['recording_mbid' => 'rec-1', 'score' => 0.9],
                        ['recording_mbid' => 'rec-2', 'score' => 0.4],
                        ['score' => 0.1], // no mbid → skipped
                    ],
                ],
            ], \JSON_THROW_ON_ERROR));
        });

        $client = new ListenBrainzClient($http, 'alice', ListenBrainzClient::DEFAULT_BASE_URL, 'tok-123');
        $recs = $client->recommendedRecordings(50);

        $this->assertSame([
            ['recording_mbid' => 'rec-1', 'score' => 0.9],
            ['recording_mbid' => 'rec-2', 'score' => 0.4],
        ], $recs);

        [$method, $url, $options] = $captured;
        $this->assertSame('GET', $method);
        $this->assertStringContainsString('/1/cf/recommendation/user/alice/recording', $url);
        $this->assertStringContainsString('count=50', $url);
        $this->assertContains('Authorization: Token tok-123', $options['headers']);
    }

    public function testResolveArtistsParsesMetadata(): void
    {
        $http = new MockHttpClient(function (string $method, string $url): MockResponse {
            return new MockResponse(json_encode([
                'rec-1' => [
                    'artist' => [
                        'name' => 'A & B',
                        'artists' => [
                            ['artist_mbid' => 'art-a', 'name' => 'A'],
                            ['artist_mbid' => 'art-b', 'name' => 'B'],
                        ],
                    ],
                ],
                'rec-2' => [
                    'artist' => ['artists' => [['artist_mbid' => 'art-c', 'name' => 'C']]],
                ],
            ], \JSON_THROW_ON_ERROR));
        });

        $client = new ListenBrainzClient($http, 'alice');
        $resolved = $client->resolveArtists(['rec-1', 'rec-2', '', 'rec-1']);

        $this->assertSame([
            ['mbid' => 'art-a', 'name' => 'A'],
            ['mbid' => 'art-b', 'name' => 'B'],
        ], $resolved['rec-1']);
        $this->assertSame([['mbid' => 'art-c', 'name' => 'C']], $resolved['rec-2']);
    }

    public function testNoContentReturnsEmpty(): void
    {
        $http = new MockHttpClient([new MockResponse('', ['http_code' => 204])]);
        $client = new ListenBrainzClient($http, 'alice');
        $this->assertSame([], $client->recommendedRecordings(10));
    }

    public function testHttpErrorIsWrapped(): void
    {
        $http = new MockHttpClient([new MockResponse('nope', ['http_code' => 500])]);
        $client = new ListenBrainzClient($http, 'alice');

        $this->expectException(ListenBrainzException::class);
        $this->expectExceptionMessage('HTTP 500');
        $client->recommendedRecordings(10);
    }
}
