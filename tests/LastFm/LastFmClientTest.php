<?php

namespace App\Tests\LastFm;

use App\LastFm\LastFmApiException;
use App\LastFm\LastFmClient;
use App\LastFm\LastFmKeyRejectedException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;

/**
 * Refused API key handling (issue #309): Last.fm Pro is required for an API
 * key from 2026-12-01 on.
 */
class LastFmClientTest extends TestCase
{
    public function testHttp403WithInvalidKeyErrorIsRejectedKeyWithoutRetry(): void
    {
        $http = new MockHttpClient([
            $this->json(['error' => 10, 'message' => 'Invalid API key - You must be granted a valid key by last.fm'], 403),
            $this->json(['recenttracks' => ['track' => []]]),
        ]);
        $client = new LastFmClient($http, pageDelaySeconds: 0);

        try {
            iterator_to_array($client->streamRecentTracks('k', 'u'));
            $this->fail('Expected LastFmKeyRejectedException');
        } catch (LastFmKeyRejectedException $e) {
            $this->assertSame(10, $e->errorCode);
            // Messenger must not retry it.
            $this->assertTrue((new \ReflectionClass($e))->implementsInterface(UnrecoverableExceptionInterface::class));
            $this->assertStringContainsString('Last.fm Pro', $e->getMessage());
            $this->assertStringContainsString('LASTFM_API_KEY', $e->getMessage());
        }
        $this->assertSame(1, $http->getRequestsCount());
    }

    public function testSuspendedKeyInBodyIsRejectedKey(): void
    {
        $client = new LastFmClient(new MockHttpClient([
            $this->json(['error' => 26, 'message' => 'Suspended API key']),
        ]), pageDelaySeconds: 0);

        $this->expectException(LastFmKeyRejectedException::class);
        $client->artistGetSimilar('k', 'Artist');
    }

    public function testOtherApiErrorStaysPlainApiException(): void
    {
        $client = new LastFmClient(new MockHttpClient([
            $this->json(['error' => 29, 'message' => 'Rate limit exceeded']),
        ]), pageDelaySeconds: 0);

        try {
            $client->artistGetSimilar('k', 'Artist');
            $this->fail('Expected LastFmApiException');
        } catch (LastFmApiException $e) {
            $this->assertNotInstanceOf(LastFmKeyRejectedException::class, $e);
            $this->assertSame(29, $e->errorCode);
        }
    }

    public function testTrackNotFoundIsStillAnEmptyLookup(): void
    {
        $client = new LastFmClient(new MockHttpClient([
            $this->json(['error' => 6, 'message' => 'Track not found']),
        ]), pageDelaySeconds: 0);

        $info = $client->trackGetCorrection('k', 'Artist', 'Title');

        $this->assertFalse($info->hasCorrection());
        $this->assertFalse($info->hasMbid());
    }

    public function testRejectedKeyOnLookupPropagates(): void
    {
        $client = new LastFmClient(new MockHttpClient([
            $this->json(['error' => 10, 'message' => 'Invalid API key'], 403),
        ]), pageDelaySeconds: 0);

        $this->expectException(LastFmKeyRejectedException::class);
        $client->trackGetCorrection('k', 'Artist', 'Title');
    }

    public function testRejectedKeyOnWriteAction(): void
    {
        $client = new LastFmClient(new MockHttpClient([
            $this->json(['error' => 10, 'message' => 'Invalid API key'], 403),
        ]), pageDelaySeconds: 0);

        $this->expectException(LastFmKeyRejectedException::class);
        $client->trackLove('k', 'secret', 'sk', 'Artist', 'Title');
    }

    public function testRejectedKeyOnMobileSession(): void
    {
        $client = new LastFmClient(new MockHttpClient([
            $this->json(['error' => 10, 'message' => 'Invalid API key'], 403),
        ]), pageDelaySeconds: 0);

        $this->expectException(LastFmKeyRejectedException::class);
        $client->authGetMobileSession('k', 'secret', 'u', 'p');
    }

    public function testHttpErrorWithoutJsonBodyStaysRequestFailure(): void
    {
        $client = new LastFmClient(new MockHttpClient([
            new MockResponse('<html>Forbidden</html>', ['http_code' => 403]),
        ]), pageDelaySeconds: 0);

        try {
            $client->artistGetSimilar('k', 'Artist');
            $this->fail('Expected a request failure');
        } catch (\RuntimeException $e) {
            $this->assertNotInstanceOf(LastFmKeyRejectedException::class, $e);
        }
    }

    /** @param array<string, mixed> $body */
    private function json(array $body, int $status = 200): MockResponse
    {
        return new MockResponse((string) json_encode($body), [
            'http_code' => $status,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }
}
