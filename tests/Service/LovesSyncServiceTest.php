<?php

namespace App\Tests\Service;

use App\LastFm\LastFmClient;
use App\LastFm\LastFmLovedTrack;
use App\Navidrome\NavidromeRepository;
use App\Service\LovesSyncService;
use PHPUnit\Framework\TestCase;

class LovesSyncServiceTest extends TestCase
{
    public function testPullAppliesMatchedUnmatchedAndAlreadyInSync(): void
    {
        $client = $this->createMock(LastFmClient::class);
        $client->method('iterateLovedTracks')->willReturnCallback(static function (): \Generator {
            yield new LastFmLovedTrack('Daft Punk', 'Da Funk', null, null);
            yield new LastFmLovedTrack('Aphex Twin', 'Xtal', null, null);
            yield new LastFmLovedTrack('Mystery Band', 'Untitled', null, null);
        });

        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->method('findMediaFileByArtistTitle')->willReturnMap([
            ['Daft Punk', 'Da Funk', 'mf-daft'],
            ['Aphex Twin', 'Xtal', 'mf-aphex'],
            ['Mystery Band', 'Untitled', null], // unmatched
        ]);
        $navidrome->method('findMediaFileByMbid')->willReturn(null);
        $navidrome->expects($this->once())->method('beginWriteTransaction');
        $navidrome->expects($this->once())->method('commitWrite');
        $navidrome->expects($this->once())->method('walCheckpointTruncate');
        $navidrome->expects($this->once())->method('closeWriteConnection');

        $navidrome->expects($this->exactly(2))->method('markStarred')
            ->willReturnOnConsecutiveCalls(true, false);

        $service = new LovesSyncService($navidrome, $client);
        $report = $service->pullLastFmToNavidrome('apiKey', 'alice', dryRun: false);

        $this->assertSame(3, $report->considered);
        $this->assertSame(1, $report->applied);
        $this->assertSame(1, $report->alreadyInSync);
        $this->assertSame(1, $report->unmatched);
        $this->assertSame(0, $report->errors);
    }

    public function testPullDryRunDoesNotWriteToNavidrome(): void
    {
        $client = $this->createMock(LastFmClient::class);
        $client->method('iterateLovedTracks')->willReturnCallback(static function (): \Generator {
            yield new LastFmLovedTrack('Daft Punk', 'Da Funk', null, null);
        });

        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->method('findMediaFileByArtistTitle')->willReturn('mf-1');
        $navidrome->method('findMediaFileByMbid')->willReturn(null);
        $navidrome->expects($this->never())->method('beginWriteTransaction');
        $navidrome->expects($this->never())->method('markStarred');
        $navidrome->expects($this->never())->method('walCheckpointTruncate');

        $service = new LovesSyncService($navidrome, $client);
        $report = $service->pullLastFmToNavidrome('apiKey', 'alice', dryRun: true);

        $this->assertSame(1, $report->considered);
        $this->assertSame(1, $report->applied);
    }

    public function testPushSkipsAlreadyLovedAndCallsTrackLoveOtherwise(): void
    {
        $client = $this->createMock(LastFmClient::class);
        // First call: existing loved list (used to skip redundant API calls).
        $client->method('iterateLovedTracks')->willReturnCallback(static function (): \Generator {
            yield new LastFmLovedTrack('Daft Punk', 'Da Funk', null, null);
        });

        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->method('iterateStarredMediaFiles')->willReturnCallback(static function (): \Generator {
            yield ['id' => 'mf-1', 'artist' => 'Daft Punk', 'title' => 'Da Funk', 'album' => 'Homework', 'mbid' => null, 'starred_at' => null];
            yield ['id' => 'mf-2', 'artist' => 'Aphex Twin', 'title' => 'Xtal', 'album' => 'SAW 85-92', 'mbid' => null, 'starred_at' => null];
        });

        $client->expects($this->once())->method('trackLove')
            ->with('apiKey', 'apiSecret', 'sk', 'Aphex Twin', 'Xtal');

        $service = new LovesSyncService($navidrome, $client);
        $report = $service->pushNavidromeToLastFm('apiKey', 'apiSecret', 'sk', 'alice');

        $this->assertSame(2, $report->considered);
        $this->assertSame(1, $report->applied);
        $this->assertSame(1, $report->alreadyInSync);
    }

    public function testPushDryRunDoesNotCallTrackLove(): void
    {
        $client = $this->createMock(LastFmClient::class);
        $client->method('iterateLovedTracks')->willReturnCallback(static function (): \Generator {
            yield from [];
        });

        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->method('iterateStarredMediaFiles')->willReturnCallback(static function (): \Generator {
            yield ['id' => 'mf-1', 'artist' => 'A', 'title' => 'B', 'album' => '', 'mbid' => null, 'starred_at' => null];
        });

        $client->expects($this->never())->method('trackLove');

        $service = new LovesSyncService($navidrome, $client);
        $report = $service->pushNavidromeToLastFm('k', 's', 'sk', 'alice', dryRun: true);

        $this->assertSame(1, $report->considered);
        $this->assertSame(1, $report->applied);
    }
}
