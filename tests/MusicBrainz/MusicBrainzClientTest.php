<?php

namespace App\Tests\MusicBrainz;

use App\MusicBrainz\MusicBrainzClient;
use App\MusicBrainz\MusicBrainzException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class MusicBrainzClientTest extends TestCase
{
    public function testEmptyNameReturnsEmptyListWithoutRequest(): void
    {
        $http = new MockHttpClient([]);
        $client = new MusicBrainzClient($http, 'navidrome-tools-tests/1.0 (test@example.org)');

        $this->assertSame([], $client->searchArtist(''));
        $this->assertSame(0, $http->getRequestsCount());
    }

    public function testParsesArtistCandidatesWithAliases(): void
    {
        $payload = json_encode([
            'artists' => [
                [
                    'id' => 'b10bbbfc-cf9e-42e0-be17-e2c3e1d2600d',
                    'name' => 'The Beatles',
                    'score' => 100,
                    'aliases' => [
                        ['name' => 'Beatles, The'],
                        ['name' => 'Beatles'],
                    ],
                ],
                [
                    'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
                    'name' => 'The Beatles Tribute',
                    'score' => 72,
                    'aliases' => [],
                ],
            ],
        ], \JSON_THROW_ON_ERROR);

        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $opts) use (&$captured, $payload): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'headers' => $opts['headers'] ?? []];
            return new MockResponse($payload, ['http_code' => 200]);
        });

        $client = new MusicBrainzClient($http, 'navidrome-tools-tests/1.0 (test@example.org)');
        $candidates = $client->searchArtist('The Beatles');

        $this->assertCount(2, $candidates);
        $this->assertSame('The Beatles', $candidates[0]->name);
        $this->assertSame(100, $candidates[0]->score);
        $this->assertSame(['The Beatles', 'Beatles, The', 'Beatles'], $candidates[0]->allNames());
        $this->assertSame('GET', $captured['method']);
        $this->assertStringContainsString('/artist/?', $captured['url']);
        $this->assertStringContainsString('navidrome-tools-tests/1.0', implode("\n", $captured['headers']));
    }

    public function testSkipsCandidatesMissingIdOrName(): void
    {
        $payload = json_encode([
            'artists' => [
                ['id' => '', 'name' => 'no id', 'score' => 90],
                ['id' => 'mbid-2', 'name' => '', 'score' => 90],
                ['id' => 'mbid-3', 'name' => 'OK', 'score' => 90],
            ],
        ], \JSON_THROW_ON_ERROR);
        $http = new MockHttpClient(new MockResponse($payload));

        $client = new MusicBrainzClient($http, 'ua');
        $candidates = $client->searchArtist('whatever');

        $this->assertCount(1, $candidates);
        $this->assertSame('OK', $candidates[0]->name);
    }

    public function testRaisesOnRateLimit503(): void
    {
        $http = new MockHttpClient(new MockResponse('', ['http_code' => 503]));
        $client = new MusicBrainzClient($http, 'ua');

        $this->expectException(MusicBrainzException::class);
        $this->expectExceptionMessageMatches('/rate-limited/i');
        $client->searchArtist('whoever');
    }

    public function testRaisesOnOther4xx5xx(): void
    {
        $http = new MockHttpClient(new MockResponse('boom', ['http_code' => 500]));
        $client = new MusicBrainzClient($http, 'ua');

        $this->expectException(MusicBrainzException::class);
        $client->searchArtist('whoever');
    }

    public function testEscapesLuceneSpecialCharsInQuery(): void
    {
        $captured = '';
        $http = new MockHttpClient(function (string $method, string $url) use (&$captured): MockResponse {
            $captured = $url;
            return new MockResponse('{"artists": []}');
        });

        $client = new MusicBrainzClient($http, 'ua');
        $client->searchArtist('AC/DC');

        // Backslash-escaped slash (`AC\/DC`), then url-encoded by the query
        // builder. The `/` itself stays literal — Symfony doesn't encode it.
        $this->assertStringContainsString('AC%5C/DC', $captured);
    }
}
