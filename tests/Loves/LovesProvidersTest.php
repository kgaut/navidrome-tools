<?php

namespace App\Tests\Loves;

use App\LastFm\LastFmClient;
use App\LastFm\LastFmLovedTrack;
use App\Loves\ActiveLovesProvider;
use App\Loves\LastFmLovesProvider;
use App\Loves\ListenBrainzLovesProvider;
use App\Loves\LovesProviderInterface;
use App\Navidrome\NavidromeRepository;
use App\Recommendation\ListenBrainzClient;
use App\Service\LastFmSessionService;
use App\Service\LovesSyncService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Issue #310: loves follow SCROBBLE_SOURCE; ListenBrainz needs a recording
 * MBID to write.
 */
class LovesProvidersTest extends TestCase
{
    public function testActiveProviderFollowsTheSource(): void
    {
        $sessions = $this->createMock(LastFmSessionService::class);
        $sessions->method('get')->willReturn('sk');
        $make = fn (string $name) => (new ActiveLovesProvider(
            $this->createMock(LastFmClient::class),
            $sessions,
            new ListenBrainzClient(new MockHttpClient([]), 'bob', ListenBrainzClient::DEFAULT_BASE_URL, 'tok'),
            $name,
            'key',
            'secret',
            'alice',
        ))->get();

        $lastFm = $make('lastfm');
        $this->assertInstanceOf(LastFmLovesProvider::class, $lastFm);
        $this->assertTrue($lastFm->canWrite());

        $lb = $make('listenbrainz');
        $this->assertInstanceOf(ListenBrainzLovesProvider::class, $lb);
        $this->assertSame('bob', $lb->user());
        $this->assertTrue($lb->canWrite());
    }

    public function testListenBrainzCannotWriteWithoutToken(): void
    {
        $this->assertFalse((new ListenBrainzLovesProvider(new ListenBrainzClient(new MockHttpClient([]), 'bob')))->canWrite());
    }

    public function testListenBrainzLoveUsesTheTagThenTheLookup(): void
    {
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = $method . ' ' . parse_url($url, \PHP_URL_PATH);

            return str_contains($url, '/metadata/lookup/')
                ? new MockResponse('{}')
                : new MockResponse('{"status":"ok"}');
        });
        $provider = new ListenBrainzLovesProvider(new ListenBrainzClient($http, 'bob', ListenBrainzClient::DEFAULT_BASE_URL, 'tok'));

        $this->assertTrue($provider->love('A', 'T', 'rec-tag'));
        $this->assertFalse($provider->love('Unknown', 'Track', null));
        $this->assertSame([
            'POST /1/feedback/recording-feedback',
            'GET /1/metadata/lookup/',
        ], $requests);
    }

    public function testPushSkipsTracksLovedByMbidAndCountsUnidentifiedAsUnmatched(): void
    {
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->method('iterateStarredMediaFiles')->willReturnCallback(static function (): \Generator {
            yield ['id' => '1', 'artist' => 'A', 'title' => 'Renamed', 'album' => '', 'mbid' => 'rec-1', 'starred_at' => null];
            yield ['id' => '2', 'artist' => 'B', 'title' => 'New', 'album' => '', 'mbid' => 'rec-2', 'starred_at' => null];
            yield ['id' => '3', 'artist' => 'C', 'title' => 'Unknown', 'album' => '', 'mbid' => null, 'starred_at' => null];
        });
        $loved = [];
        $provider = $this->provider([new LastFmLovedTrack('A', 'Original', 'rec-1', null)], $loved);

        $report = (new LovesSyncService($navidrome, $this->createMock(LastFmClient::class)))->pushFromNavidrome($provider);

        $this->assertSame(3, $report->considered);
        $this->assertSame(1, $report->alreadyInSync);
        $this->assertSame(1, $report->applied);
        $this->assertSame(1, $report->unmatched);
        $this->assertSame([['B', 'New', 'rec-2'], ['C', 'Unknown', null]], $loved);
    }

    public function testPushTreatsATrackResolvingToALovedMediaFileAsInSync(): void
    {
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->method('iterateStarredMediaFiles')->willReturnCallback(static function (): \Generator {
            // Starred by a pull from a loved track spelled differently.
            yield ['id' => 'mf-7', 'artist' => 'The Artist', 'title' => 'Song (Remastered)', 'album' => '', 'mbid' => null, 'starred_at' => null];
        });
        $navidrome->method('findMediaFileByMbid')->willReturn(null);
        $navidrome->method('findMediaFileByArtistTitle')->willReturnMap([['Artist', 'Song', 'mf-7']]);
        $loved = [];
        $provider = $this->provider([new LastFmLovedTrack('Artist', 'Song', null, null)], $loved);

        $report = (new LovesSyncService($navidrome, $this->createMock(LastFmClient::class)))->pushFromNavidrome($provider);

        $this->assertSame(1, $report->alreadyInSync);
        $this->assertSame([], $loved);
    }

    public function testPushDryRunForecastsUnidentifiableTracks(): void
    {
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->method('iterateStarredMediaFiles')->willReturnCallback(static function (): \Generator {
            yield ['id' => '1', 'artist' => 'A', 'title' => 'Tagged', 'album' => '', 'mbid' => 'rec-1', 'starred_at' => null];
            yield ['id' => '2', 'artist' => 'B', 'title' => 'Untagged', 'album' => '', 'mbid' => null, 'starred_at' => null];
        });
        $provider = $this->createMock(LovesProviderInterface::class);
        $provider->method('iterateLoved')->willReturn([]);
        $provider->method('identify')->willReturnCallback(static fn (string $a, string $t, ?string $m): ?string => $m);
        $provider->expects($this->never())->method('love');

        $report = (new LovesSyncService($navidrome, $this->createMock(LastFmClient::class)))->pushFromNavidrome($provider, dryRun: true);

        $this->assertSame(1, $report->applied);
        $this->assertSame(1, $report->unmatched);
    }

    /**
     * @param list<LastFmLovedTrack>    $existing
     * @param list<array{0: string, 1: string, 2: ?string}> $loved collects love() calls
     */
    private function provider(array $existing, array &$loved): LovesProviderInterface
    {
        $provider = $this->createMock(LovesProviderInterface::class);
        $provider->method('user')->willReturn('bob');
        $provider->method('iterateLoved')->willReturn($existing);
        $provider->method('love')->willReturnCallback(static function (string $a, string $t, ?string $m) use (&$loved): bool {
            $loved[] = [$a, $t, $m];

            return $m !== null;
        });

        return $provider;
    }
}
