<?php

namespace App\Tests\Recommendation;

use App\LastFm\LastFmScrobble;
use App\Recommendation\ListenBrainzClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Issue #310: listens read from ListenBrainz, paginated backwards by max_ts.
 */
class ListenBrainzClientListensTest extends TestCase
{
    public function testUnconfiguredClientYieldsNothing(): void
    {
        $client = new ListenBrainzClient(new MockHttpClient([]), '');

        $this->assertSame([], iterator_to_array($client->streamListens(), false));
    }

    public function testMapsListenFieldsPreferringTheMusicBrainzMapping(): void
    {
        $http = new MockHttpClient([$this->page([
            [
                'listened_at' => 1_764_000_000,
                'recording_msid' => 'msid-1',
                'track_metadata' => [
                    'artist_name' => 'Artist',
                    'track_name' => 'Title',
                    'release_name' => 'Album',
                    'additional_info' => ['recording_mbid' => 'sent-rec', 'artist_mbids' => ['sent-art']],
                    'mbid_mapping' => ['recording_mbid' => 'mapped-rec', 'artist_mbids' => ['mapped-art'], 'release_mbid' => 'mapped-rel'],
                ],
            ],
            [
                'listened_at' => 1_763_999_000,
                'recording_msid' => 'msid-2',
                'track_metadata' => [
                    'artist_name' => 'Other',
                    'track_name' => 'Song',
                    'additional_info' => ['recording_mbid' => 'sent-only'],
                ],
            ],
        ])]);

        $listens = iterator_to_array((new ListenBrainzClient($http, 'alice'))->streamListens(), false);

        $this->assertCount(2, $listens);
        $first = $listens[0];
        $this->assertSame(['Artist', 'Title', 'Album', 'mapped-rec', 'mapped-art', 'mapped-rel'], [
            $first->artist, $first->title, $first->album, $first->mbid, $first->mbidArtist, $first->mbidAlbum,
        ]);
        $this->assertSame('2025-11-24 16:00:00', $first->playedAt->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $first->playedAt->getTimezone()->getName());
        $this->assertSame('sent-only', $listens[1]->mbid);
        $this->assertNull($listens[1]->mbidArtist);
    }

    public function testPagesBackwardsAndYieldsBoundarySecondOnce(): void
    {
        $urls = [];
        // Page 1: full page whose oldest second (1000) is shared with page 2.
        $page1 = [];
        for ($i = 0; $i < ListenBrainzClient::LISTENS_PAGE_SIZE - 1; $i++) {
            $page1[] = $this->listen(2000 - $i, 'msid-' . $i);
        }
        $page1[] = $this->listen(1000, 'boundary');
        $page2 = [$this->listen(1000, 'boundary'), $this->listen(999, 'last')];

        $http = new MockHttpClient(function (string $method, string $url) use (&$urls, $page1, $page2): MockResponse {
            $urls[] = $url;

            return count($urls) === 1 ? $this->page($page1) : $this->page($page2);
        });

        $listens = iterator_to_array((new ListenBrainzClient($http, 'alice'))->streamListens(), false);

        $this->assertCount(ListenBrainzClient::LISTENS_PAGE_SIZE + 1, $listens);
        $this->assertCount(2, $urls);
        $this->assertStringNotContainsString('max_ts', $urls[0]);
        $this->assertStringContainsString('max_ts=1001', $urls[1]);
    }

    public function testStopsAtDateMinAndBoundsDateMaxInclusively(): void
    {
        $urls = [];
        $http = new MockHttpClient(function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $url;

            return $this->page([$this->listen(500, 'a'), $this->listen(300, 'b'), $this->listen(100, 'c')]);
        });

        $listens = iterator_to_array((new ListenBrainzClient($http, 'alice'))->streamListens(
            new \DateTimeImmutable('@300'),
            new \DateTimeImmutable('@500'),
        ), false);

        $this->assertSame([500, 300], array_map(static fn (LastFmScrobble $s): int => $s->playedAt->getTimestamp(), $listens));
        $this->assertCount(1, $urls);
        $this->assertStringContainsString('max_ts=501', $urls[0]);
    }

    public function testRetriesAfterRateLimitAndSleepsTheAnnouncedDelay(): void
    {
        $slept = [];
        $http = new MockHttpClient([
            new MockResponse('', ['http_code' => 429, 'response_headers' => ['X-RateLimit-Reset-In' => '7']]),
            $this->page([$this->listen(100, 'a')]),
        ]);
        $client = new ListenBrainzClient($http, 'alice', sleeper: static function (int $s) use (&$slept): void {
            $slept[] = $s;
        });

        $listens = iterator_to_array($client->streamListens(), false);

        $this->assertCount(1, $listens);
        $this->assertSame([7], $slept);
    }

    public function testSkipsListensWithoutArtistOrTitle(): void
    {
        $http = new MockHttpClient([$this->page([
            ['listened_at' => 100, 'track_metadata' => ['artist_name' => '', 'track_name' => 'T']],
            ['listened_at' => 0, 'track_metadata' => ['artist_name' => 'A', 'track_name' => 'T']],
            $this->listen(50, 'ok'),
        ])]);

        $this->assertCount(1, iterator_to_array((new ListenBrainzClient($http, 'alice'))->streamListens(), false));
    }

    /**
     * @return array<string, mixed>
     */
    private function listen(int $ts, string $msid): array
    {
        return [
            'listened_at' => $ts,
            'recording_msid' => $msid,
            'track_metadata' => ['artist_name' => 'Artist ' . $msid, 'track_name' => 'Title ' . $msid],
        ];
    }

    /**
     * @param list<array<string, mixed>> $listens
     */
    private function page(array $listens): MockResponse
    {
        return new MockResponse(json_encode(['payload' => ['count' => count($listens), 'listens' => $listens]], \JSON_THROW_ON_ERROR));
    }
}
