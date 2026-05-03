<?php

namespace App\Tests\LastFm;

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

    public function testTrackGetInfoErrorPropagatesAsRuntimeException(): void
    {
        $http = new MockHttpClient([
            new MockResponse(json_encode([
                'error' => 6,
                'message' => 'Track not found',
            ], JSON_THROW_ON_ERROR)),
        ]);

        $client = new LastFmClient($http, 0);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Last\.fm API error 6:/');
        $client->trackGetInfo('apikey', 'Unknown', 'Untrack');
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
