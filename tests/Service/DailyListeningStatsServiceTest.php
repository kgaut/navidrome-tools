<?php

namespace App\Tests\Service;

use App\Navidrome\NavidromeRepository;
use App\Repository\ScrobbleRepository;
use App\Service\DailyListeningStatsService;
use App\Service\LastFmStatsService;
use PHPUnit\Framework\TestCase;

class DailyListeningStatsServiceTest extends TestCase
{
    public function testNavidromeSourceZeroFillsAndAlwaysReports100Coverage(): void
    {
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->method('getStarredAddedByDay')->willReturn(['2026-06-02' => 2]);
        $navidrome->method('getDailyListening')->willReturn([
            '2026-06-01' => ['tracks' => 3, 'distinct_tracks' => 2, 'artists' => 2, 'albums' => 1, 'duration_seconds' => 600],
        ]);
        $navidrome->method('getTopArtistByDay')->willReturn([
            '2026-06-01' => ['name' => 'Stupeflip', 'plays' => 2],
        ]);

        $service = new DailyListeningStatsService(
            $navidrome,
            $this->createMock(ScrobbleRepository::class),
            $this->createMock(LastFmStatsService::class),
            'UTC',
        );

        $days = $service->daily('navidrome', new \DateTimeImmutable('2026-06-01'), new \DateTimeImmutable('2026-06-02'));

        $this->assertCount(2, $days);
        $this->assertSame([
            'day' => '2026-06-01', 'tracks' => 3, 'distinct_tracks' => 2, 'artists' => 2, 'albums' => 1,
            'duration_seconds' => 600, 'duration_coverage_pct' => 100, 'loved_added' => 0,
            'top_artist' => ['name' => 'Stupeflip', 'plays' => 2],
        ], $days[0]);
        // Day with no listening → all zeros, but loved_added from starred_at is still counted.
        $this->assertSame([
            'day' => '2026-06-02', 'tracks' => 0, 'distinct_tracks' => 0, 'artists' => 0, 'albums' => 0,
            'duration_seconds' => 0, 'duration_coverage_pct' => 100, 'loved_added' => 2, 'top_artist' => null,
        ], $days[1]);
    }

    public function testLastfmSourceComputesDurationAndCoverageFromMatchedTargets(): void
    {
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->method('getStarredAddedByDay')->willReturn([]); // loved always from Navidrome, none here
        $navidrome->method('getMediaFileMetadata')->with(['mf1'])->willReturn([
            ['id' => 'mf1', 'artist' => 'Y', 'album' => 'A', 'year' => 2020, 'duration' => 200],
        ]);

        $scrobbles = $this->createMock(ScrobbleRepository::class);
        $scrobbles->method('getDailyListening')->willReturn([
            '2026-06-01' => ['tracks' => 4, 'distinct_tracks' => 3, 'artists' => 2, 'albums' => 2],
        ]);
        $scrobbles->method('getTopArtistByDay')->willReturn([
            ['day' => '2026-06-01', 'artist' => 'Y', 'plays' => 3],
            ['day' => '2026-06-01', 'artist' => 'Z', 'plays' => 1],
        ]);
        // 3 plays matched to mf1 (200s each), 1 play unmatched → coverage 75%, duration 600s.
        $scrobbles->method('getDailyMatchedTargets')->willReturn([
            ['day' => '2026-06-01', 'target_id' => 'mf1', 'plays' => 3],
            ['day' => '2026-06-01', 'target_id' => null, 'plays' => 1],
        ]);

        $lastfm = $this->createMock(LastFmStatsService::class);
        $lastfm->method('resolveUser')->willReturn('alice');

        $service = new DailyListeningStatsService($navidrome, $scrobbles, $lastfm, 'UTC');

        $days = $service->daily('lastfm', new \DateTimeImmutable('2026-06-01'), new \DateTimeImmutable('2026-06-01'));

        $this->assertCount(1, $days);
        $this->assertSame(4, $days[0]['tracks']);
        $this->assertSame(600, $days[0]['duration_seconds']);
        $this->assertSame(75, $days[0]['duration_coverage_pct']);
        $this->assertSame(['name' => 'Y', 'plays' => 3], $days[0]['top_artist']);
    }

    public function testHistorySourceWithoutUserQueriesTheWholeTableAndZeroFills(): void
    {
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->method('getStarredAddedByDay')->willReturn([]);
        $lastfm = $this->createMock(LastFmStatsService::class);
        $lastfm->method('resolveUser')->willReturn(null); // HISTORY_USER empty → whole table (#310)
        $scrobbles = $this->createMock(ScrobbleRepository::class);
        $scrobbles->expects($this->once())->method('getDailyListening')->with(null)->willReturn([]);

        $service = new DailyListeningStatsService(
            $navidrome,
            $scrobbles,
            $lastfm,
            'UTC',
        );

        $days = $service->daily('lastfm', new \DateTimeImmutable('2026-06-01'), new \DateTimeImmutable('2026-06-03'));

        $this->assertCount(3, $days);
        foreach ($days as $d) {
            $this->assertSame(0, $d['tracks']);
            $this->assertSame(100, $d['duration_coverage_pct']);
            $this->assertNull($d['top_artist']);
        }
    }

    public function testHistoryAndItsLastfmAliasReadTheSameTable(): void
    {
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->method('getStarredAddedByDay')->willReturn([]);
        $navidrome->expects($this->never())->method('getDailyListening');
        $scrobbles = $this->createMock(ScrobbleRepository::class);
        $scrobbles->expects($this->exactly(2))->method('getDailyListening')->willReturn([
            '2026-06-01' => ['tracks' => 2, 'distinct_tracks' => 2, 'artists' => 1, 'albums' => 1],
        ]);

        $service = new DailyListeningStatsService($navidrome, $scrobbles, $this->createMock(LastFmStatsService::class), 'UTC');
        $day = new \DateTimeImmutable('2026-06-01');

        $this->assertSame(
            $service->daily(DailyListeningStatsService::SOURCE_HISTORY, $day, $day),
            $service->daily(DailyListeningStatsService::SOURCE_LASTFM, $day, $day),
        );
    }

    public function testTimezoneIsExposed(): void
    {
        $service = new DailyListeningStatsService(
            $this->createMock(NavidromeRepository::class),
            $this->createMock(ScrobbleRepository::class),
            $this->createMock(LastFmStatsService::class),
            'Europe/Paris',
        );
        $this->assertSame('Europe/Paris', $service->timezone());
    }
}
