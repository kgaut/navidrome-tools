<?php

namespace App\Tests\Subsonic;

use App\Subsonic\SubsonicClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class SubsonicClientTest extends TestCase
{
    public function testGetStarredReturnsListOfSongs(): void
    {
        $client = new MockHttpClient([
            $this->ok([
                'starred' => [
                    'song' => [
                        ['id' => 'mf-1', 'title' => 'Halo', 'artist' => 'Beyoncé', 'album' => 'I Am'],
                        ['id' => 'mf-2', 'title' => 'Smile', 'artist' => 'Lily Allen', 'album' => 'Alright Still'],
                    ],
                ],
            ]),
        ]);

        $sub = new SubsonicClient($client, 'http://navi.test', 'admin', 'changeme');
        $starred = $sub->getStarred();

        $this->assertCount(2, $starred);
        $this->assertSame('mf-1', $starred[0]['id']);
        $this->assertSame('Halo', $starred[0]['title']);
        $this->assertSame('Beyoncé', $starred[0]['artist']);
        $this->assertSame('I Am', $starred[0]['album']);
        $this->assertSame('mf-2', $starred[1]['id']);
    }

    public function testGetStarredEmptyResponse(): void
    {
        $client = new MockHttpClient([$this->ok(['starred' => []])]);
        $sub = new SubsonicClient($client, 'http://navi.test', 'admin', 'changeme');
        $this->assertSame([], $sub->getStarred());
    }

    public function testStarTracksSendsAllIdsInOneCall(): void
    {
        $captured = null;
        $client = new MockHttpClient(function (string $method, string $url) use (&$captured): MockResponse {
            $captured = $url;

            return $this->ok([]);
        });

        $sub = new SubsonicClient($client, 'http://navi.test', 'admin', 'changeme');
        $sub->starTracks('mf-1', 'mf-2', 'mf-3');

        $this->assertNotNull($captured);
        $this->assertStringContainsString('/rest/star.view?', $captured);
        $this->assertStringContainsString('id=mf-1', $captured);
        $this->assertStringContainsString('id=mf-2', $captured);
        $this->assertStringContainsString('id=mf-3', $captured);
    }

    public function testStarTracksBatchesPast50Ids(): void
    {
        $calls = 0;
        $client = new MockHttpClient(function () use (&$calls): MockResponse {
            $calls++;

            return $this->ok([]);
        });

        $sub = new SubsonicClient($client, 'http://navi.test', 'admin', 'changeme');
        $sub->starTracks(...array_map(static fn (int $i) => 'mf-' . $i, range(1, 75)));

        $this->assertSame(2, $calls, '75 ids should be split into 50 + 25');
    }

    public function testStarTracksNoOpWhenEmpty(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new \RuntimeException('Should not call HTTP for empty starTracks()');
        });

        $sub = new SubsonicClient($client, 'http://navi.test', 'admin', 'changeme');
        $sub->starTracks();
        $sub->unstarTracks('');
        $this->expectNotToPerformAssertions();
    }

    public function testUnstarTracksHitsCorrectEndpoint(): void
    {
        $captured = null;
        $client = new MockHttpClient(function (string $method, string $url) use (&$captured): MockResponse {
            $captured = $url;

            return $this->ok([]);
        });

        $sub = new SubsonicClient($client, 'http://navi.test', 'admin', 'changeme');
        $sub->unstarTracks('mf-1');

        $this->assertNotNull($captured);
        $this->assertStringContainsString('/rest/unstar.view?', $captured);
        $this->assertStringContainsString('id=mf-1', $captured);
    }

    public function testFetchCoverArtReturnsBinary(): void
    {
        $bytes = "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 32); // JPEG magic + filler
        $client = new MockHttpClient([
            new MockResponse($bytes, [
                'response_headers' => ['content-type: image/jpeg'],
                'http_code' => 200,
            ]),
        ]);

        $sub = new SubsonicClient($client, 'http://navi.test', 'admin', 'changeme');
        $this->assertSame($bytes, $sub->fetchCoverArt('mf-123', 128));
    }

    public function testFetchCoverArtClampsHugeSizeTo1024(): void
    {
        $captured = null;
        $client = new MockHttpClient(function (string $method, string $url) use (&$captured): MockResponse {
            $captured = $url;

            return new MockResponse("\xFF\xD8\xFF", [
                'response_headers' => ['content-type: image/jpeg'],
                'http_code' => 200,
            ]);
        });

        $sub = new SubsonicClient($client, 'http://navi.test', 'admin', 'changeme');
        $sub->fetchCoverArt('mf-123', 99999);

        $this->assertNotNull($captured);
        $this->assertStringContainsString('size=1024', (string) $captured);
        $this->assertStringNotContainsString('size=99999', (string) $captured);
    }

    public function testFetchCoverArtRejectsHttpError(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 404]),
        ]);

        $sub = new SubsonicClient($client, 'http://navi.test', 'admin', 'changeme');
        $this->expectException(\RuntimeException::class);
        $sub->fetchCoverArt('does-not-exist', 128);
    }

    public function testFetchCoverArtRejectsJsonErrorPayload(): void
    {
        // Navidrome occasionally answers 200 + JSON error envelope when
        // the id is unknown — we must not write that to the cache.
        $client = new MockHttpClient([
            new MockResponse('{"error":{"code":70,"message":"not found"}}', [
                'response_headers' => ['content-type: application/json'],
                'http_code' => 200,
            ]),
        ]);

        $sub = new SubsonicClient($client, 'http://navi.test', 'admin', 'changeme');
        $this->expectException(\RuntimeException::class);
        $sub->fetchCoverArt('weird-id', 128);
    }

    public function testFetchCoverArtRejectsEmptyId(): void
    {
        $sub = new SubsonicClient(new MockHttpClient(static function () {
            throw new \RuntimeException('Should not perform HTTP for empty id.');
        }), 'http://navi.test', 'admin', 'changeme');

        $this->expectException(\RuntimeException::class);
        $sub->fetchCoverArt('', 128);
    }

    public function testStartScanHitsStartScanEndpoint(): void
    {
        $captured = null;
        $client = new MockHttpClient(function (string $method, string $url) use (&$captured): MockResponse {
            $captured = $url;

            return $this->ok([]);
        });

        $sub = new SubsonicClient($client, 'http://navi.test', 'admin', 'changeme');
        $this->assertTrue($sub->startScan(false));

        $this->assertNotNull($captured);
        $this->assertStringContainsString('/rest/startScan.view?', $captured);
        $this->assertStringNotContainsString('fullScan=true', $captured);
    }

    public function testStartScanFullScanFlag(): void
    {
        $captured = null;
        $client = new MockHttpClient(function (string $method, string $url) use (&$captured): MockResponse {
            $captured = $url;

            return $this->ok([]);
        });

        $sub = new SubsonicClient($client, 'http://navi.test', 'admin', 'changeme');
        $this->assertTrue($sub->startScan(true));

        $this->assertNotNull($captured);
        $this->assertStringContainsString('fullScan=true', $captured);
    }

    public function testStartScanReturnsFalseOnError(): void
    {
        $client = new MockHttpClient([
            new MockResponse('', ['http_code' => 500]),
        ]);

        $sub = new SubsonicClient($client, 'http://navi.test', 'admin', 'changeme');
        $this->assertFalse($sub->startScan());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function ok(array $payload): MockResponse
    {
        return new MockResponse(
            json_encode(['subsonic-response' => array_merge(['status' => 'ok'], $payload)], JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type: application/json']],
        );
    }
}
