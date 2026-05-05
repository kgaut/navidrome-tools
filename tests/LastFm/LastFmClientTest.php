<?php

namespace App\Tests\LastFm;

use App\LastFm\LastFmApiException;
use App\LastFm\LastFmClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class LastFmClientTest extends TestCase
{
    public function testTrackGetInfoReturnsMbidAndCorrectedNames(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode([
                'track' => [
                    'name' => 'Take Me to Church',
                    'mbid' => 'aaa-bbb-ccc',
                    'artist' => ['name' => 'Hozier'],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        // Artist input has a typo Last.fm fixes (Hozire → Hozier), title
        // has a typo too (Chruch → Church). Both corrections must surface.
        $info = (new LastFmClient($http, 0))->trackGetInfo('apikey', 'Hozire', 'Take Me to Chruch');

        $this->assertSame('aaa-bbb-ccc', $info->mbid);
        $this->assertSame('Hozier', $info->correctedArtist);
        $this->assertSame('Take Me to Church', $info->correctedTitle);
    }

    public function testTrackGetInfoEmptyMbidIsCoercedToNull(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode([
                'track' => [
                    'name' => 'Some Track',
                    'mbid' => '',
                    'artist' => ['name' => 'Some Artist'],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $info = (new LastFmClient($http, 0))->trackGetInfo('apikey', 'Some Artist', 'Some Track');
        $this->assertNull($info->mbid);
    }

    public function testTrackGetInfoCorrectionIsNullWhenNamesMatchAutocorrectEcho(): void
    {
        // Last.fm returns the original spelling even when nothing was
        // corrected — case-insensitive trim equality must collapse to null.
        $http = new MockHttpClient([
            new MockResponse(json_encode([
                'track' => [
                    'name' => 'Take Me to Church',
                    'mbid' => 'mbid-1',
                    'artist' => ['name' => 'Hozier'],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $info = (new LastFmClient($http, 0))->trackGetInfo('apikey', 'hozier  ', '  Take Me to Church');
        $this->assertNull($info->correctedArtist);
        $this->assertNull($info->correctedTitle);
        $this->assertSame('mbid-1', $info->mbid);
    }

    public function testTrackGetInfoSwallowsTrackNotFoundError(): void
    {
        // Last.fm error 6 = « Track not found ». Expected for any scrobble
        // whose artist/title isn't in Last.fm's catalog — must NOT crash
        // the import, just return an empty result so the cascade continues.
        $http = new MockHttpClient([
            new MockResponse(json_encode([
                'error' => 6,
                'message' => 'Track not found',
            ], JSON_THROW_ON_ERROR)),
        ]);

        $info = (new LastFmClient($http, 0))->trackGetInfo('apikey', 'Unknown', 'Untrack');

        $this->assertNull($info->mbid);
        $this->assertNull($info->correctedArtist);
        $this->assertNull($info->correctedTitle);
        $this->assertFalse($info->hasMbid());
        $this->assertFalse($info->hasCorrection());
    }

    public function testTrackGetInfoPropagatesNonTrackNotFoundErrors(): void
    {
        // Real failures (rate limit, invalid key, service down) must
        // bubble up so the import surfaces them instead of silently
        // hammering the API.
        $http = new MockHttpClient([
            new MockResponse(json_encode([
                'error' => 29,
                'message' => 'Rate limit exceeded',
            ], JSON_THROW_ON_ERROR)),
        ]);

        $client = new LastFmClient($http, 0);

        try {
            $client->trackGetInfo('apikey', 'Some', 'Track');
            $this->fail('Expected LastFmApiException to be thrown');
        } catch (LastFmApiException $e) {
            $this->assertSame(29, $e->errorCode);
            $this->assertSame('Rate limit exceeded', $e->errorMessage);
        }
    }

    public function testCallThrowsLastFmApiExceptionWithErrorCode(): void
    {
        // Indirect check via a method that goes through call() but is
        // outside the lookup() try/catch — artistGetSimilar surfaces any
        // error code untouched.
        $http = new MockHttpClient([
            new MockResponse(json_encode([
                'error' => 10,
                'message' => 'Invalid API key',
            ], JSON_THROW_ON_ERROR)),
        ]);

        $client = new LastFmClient($http, 0);

        try {
            $client->artistGetSimilar('badkey', 'Daft Punk');
            $this->fail('Expected LastFmApiException to be thrown');
        } catch (LastFmApiException $e) {
            $this->assertSame(10, $e->errorCode);
            $this->assertStringContainsString('Last.fm API error 10', $e->getMessage());
        }
    }

    public function testTrackGetCorrectionReadsNestedCorrectionTrackNode(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode([
                'corrections' => [
                    '@attr' => ['index' => '0'],
                ],
                'correction' => [
                    'track' => [
                        'name' => 'Daft Punk',
                        'artist' => ['name' => 'Daft Punk'],
                        'mbid' => '',
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        // Artist input has a typo (Daft Pnk → Daft Punk), title also a
        // typo (Daft Funk → Daft Punk). Both surface ; case-only diffs
        // are NOT counted as corrections (covered by another test).
        $info = (new LastFmClient($http, 0))->trackGetCorrection('apikey', 'Daft Pnk', 'Daft Funk');
        $this->assertSame('Daft Punk', $info->correctedArtist);
        $this->assertSame('Daft Punk', $info->correctedTitle);
        $this->assertNull($info->mbid);
    }

    public function testArtistGetSimilarReturnsRankedNeighbours(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode([
                'similarartists' => [
                    'artist' => [
                        [
                            'name' => 'Justice',
                            'mbid' => 'mbid-justice',
                            'match' => '0.95',
                            'url' => 'https://www.last.fm/music/Justice',
                        ],
                        [
                            'name' => 'Mr. Oizo',
                            'mbid' => '',
                            'match' => '0.72',
                            'url' => 'https://www.last.fm/music/Mr.+Oizo',
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $similar = (new LastFmClient($http, 0))->artistGetSimilar('apikey', 'Daft Punk', 5);

        $this->assertCount(2, $similar);
        $this->assertSame('Justice', $similar[0]['name']);
        $this->assertSame('mbid-justice', $similar[0]['mbid']);
        $this->assertSame(0.95, $similar[0]['match']);
        $this->assertNull($similar[1]['mbid'], 'empty MBID coerced to null');
    }

    public function testCallRetriesOnTransient500AndSucceeds(): void
    {
        // Last.fm sometimes 500s mid-history during long imports — the
        // client must absorb up to two transient failures and resume on
        // the third attempt without bubbling up.
        $http = new MockHttpClient([
            new MockResponse('boom', ['http_code' => 500]),
            new MockResponse('boom', ['http_code' => 500]),
            new MockResponse(json_encode([
                'similarartists' => ['artist' => []],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $client = new LastFmClient($http, 0);

        // Should not throw — third attempt succeeds.
        $similar = $client->artistGetSimilar('apikey', 'Daft Punk');
        $this->assertSame([], $similar);
        $this->assertSame(3, $http->getRequestsCount());
    }

    public function testCallGivesUpAfterThreeFailedAttempts(): void
    {
        $http = new MockHttpClient([
            new MockResponse('boom', ['http_code' => 500]),
            new MockResponse('boom', ['http_code' => 500]),
            new MockResponse('boom', ['http_code' => 500]),
        ]);

        $client = new LastFmClient($http, 0);

        try {
            $client->artistGetSimilar('apikey', 'Daft Punk');
            $this->fail('Expected RuntimeException to be thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('after 3 attempt(s)', $e->getMessage());
            $this->assertSame(3, $http->getRequestsCount());
        }
    }

    public function testCallDoesNotRetryOnApplicationLevelErrorPayload(): void
    {
        // `error` in the JSON body is an application-level failure (e.g.
        // invalid API key) — retrying just hammers the API. Must surface
        // immediately as LastFmApiException.
        $http = new MockHttpClient([
            new MockResponse(json_encode([
                'error' => 10,
                'message' => 'Invalid API key',
            ], JSON_THROW_ON_ERROR)),
        ]);

        $client = new LastFmClient($http, 0);

        $this->expectException(LastFmApiException::class);
        try {
            $client->artistGetSimilar('badkey', 'Daft Punk');
        } finally {
            $this->assertSame(1, $http->getRequestsCount());
        }
    }

    public function testCallDoesNotRetryOn4xxClientErrors(): void
    {
        // 4xx is the caller's fault (bad params, missing key) — retrying
        // wastes calls. Only 5xx and 429 are treated as transient.
        $http = new MockHttpClient([
            new MockResponse('not found', ['http_code' => 404]),
        ]);

        $client = new LastFmClient($http, 0);

        try {
            $client->artistGetSimilar('apikey', 'Daft Punk');
            $this->fail('Expected RuntimeException to be thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('after 1 attempt(s)', $e->getMessage());
            $this->assertSame(1, $http->getRequestsCount());
        }
    }

    public function testArtistGetSimilarHandlesSingleObjectShape(): void
    {
        // Last.fm collapses to a single object when only one neighbour is found.
        $http = new MockHttpClient([
            new MockResponse(json_encode([
                'similarartists' => [
                    'artist' => [
                        'name' => 'Lone Match',
                        'mbid' => 'mbid-lone',
                        'match' => '0.5',
                        'url' => '',
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $similar = (new LastFmClient($http, 0))->artistGetSimilar('apikey', 'Anyone', 1);
        $this->assertCount(1, $similar);
        $this->assertSame('Lone Match', $similar[0]['name']);
    }
}
