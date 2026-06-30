<?php

namespace App\Tests\Service;

use App\Navidrome\NavidromeRepository;
use App\Service\DisparityStatsService;
use App\Service\LastFmStatsService;
use PHPUnit\Framework\TestCase;

class DisparityStatsServiceTest extends TestCase
{
    /**
     * @param list<array{month: string, plays: int}> $lastfmMonths
     * @param list<array{month: string, plays: int}> $naviMonths
     */
    private function makeService(?string $anchor, array $lastfmMonths, array $naviMonths): DisparityStatsService
    {
        $navi = $this->createMock(NavidromeRepository::class);
        $navi->method('getFirstScrobbleMonth')->willReturn($anchor);
        $navi->method('getPlaysByMonthSince')->willReturn($naviMonths);

        $lastfm = $this->createMock(LastFmStatsService::class);
        $lastfm->method('playsByMonthSince')->willReturn($lastfmMonths);

        return new DisparityStatsService($navi, $lastfm, 'alice');
    }

    public function testEmptyPayloadWhenAnchorIsNull(): void
    {
        $service = $this->makeService(null, [], []);
        $r = $service->compute();

        $this->assertNull($r['anchor_month']);
        $this->assertSame([], $r['by_month']);
        $this->assertSame([], $r['by_year']);
    }

    public function testCoverageSeriesIsChronologicalAndFull(): void
    {
        $service = $this->makeService(
            '2024-01',
            [
                ['month' => '2024-03', 'plays' => 300],
                ['month' => '2024-01', 'plays' => 100],
                ['month' => '2024-02', 'plays' => 500],
            ],
            [
                ['month' => '2024-01', 'plays' => 100], // 100 % — kept in series (dropped from by_month)
                ['month' => '2024-02', 'plays' => 50],  // 10 %
                ['month' => '2024-03', 'plays' => 200], // 67 %
            ],
        );

        $series = $service->compute()['coverage_series'];

        // Chronological (oldest → newest) and includes the 100 %-covered month.
        $this->assertSame(['2024-01', '2024-02', '2024-03'], array_column($series, 'month'));
        $this->assertSame([100, 10, 67], array_column($series, 'coverage_pct'));
    }

    public function testTopMonthsSortedByGapDesc(): void
    {
        $service = $this->makeService(
            '2024-01',
            lastfmMonths: [
                ['month' => '2024-01', 'plays' => 100],
                ['month' => '2024-02', 'plays' => 500],
                ['month' => '2024-03', 'plays' => 300],
            ],
            naviMonths: [
                ['month' => '2024-01', 'plays' => 90],   // gap 10
                ['month' => '2024-02', 'plays' => 50],   // gap 450
                ['month' => '2024-03', 'plays' => 200],  // gap 100
            ],
        );
        $r = $service->compute();

        $this->assertSame('2024-01', $r['anchor_month']);
        $this->assertCount(3, $r['by_month']);
        $this->assertSame('2024-02', $r['by_month'][0]['month']);
        $this->assertSame(450, $r['by_month'][0]['gap']);
        $this->assertSame(10, $r['by_month'][0]['coverage_pct']);
        $this->assertSame('2024-03', $r['by_month'][1]['month']);
        $this->assertSame('2024-01', $r['by_month'][2]['month']);
    }

    public function testPerfectlyCoveredMonthsAreFilteredOut(): void
    {
        $service = $this->makeService(
            '2024-01',
            lastfmMonths: [
                ['month' => '2024-01', 'plays' => 100],
                ['month' => '2024-02', 'plays' => 50],
            ],
            naviMonths: [
                ['month' => '2024-01', 'plays' => 100],  // gap 0 → drop
                ['month' => '2024-02', 'plays' => 50],   // gap 0 → drop
            ],
        );
        $r = $service->compute();

        $this->assertSame([], $r['by_month']);
        $this->assertSame([], $r['by_year']);
    }

    public function testGapAndCoverageClampedWhenNavidromeExceedsLastfm(): void
    {
        // Navidrome > Last.fm during a sync glitch → gap stays at 0 and we
        // skip the row rather than report a "surplus" line.
        $service = $this->makeService(
            '2024-01',
            lastfmMonths: [['month' => '2024-01', 'plays' => 100]],
            naviMonths: [['month' => '2024-01', 'plays' => 150]],
        );
        $r = $service->compute();

        $this->assertSame([], $r['by_month']);
    }

    public function testYearAggregationSumsAllMonthsNotJustTop(): void
    {
        // 14 months across two years; 2024 has 12 small gaps summing to 600,
        // 2025 has 2 big gaps summing to 1000. By-year must reflect the
        // full year sums, not just the months that made the by_month top.
        $lastfm = [];
        $navi = [];
        for ($m = 1; $m <= 12; $m++) {
            $lastfm[] = ['month' => sprintf('2024-%02d', $m), 'plays' => 100];
            $navi[] = ['month' => sprintf('2024-%02d', $m), 'plays' => 50]; // gap 50 / month → 600
        }
        $lastfm[] = ['month' => '2025-01', 'plays' => 1000];
        $navi[] = ['month' => '2025-01', 'plays' => 200]; // gap 800
        $lastfm[] = ['month' => '2025-02', 'plays' => 300];
        $navi[] = ['month' => '2025-02', 'plays' => 100]; // gap 200

        $service = $this->makeService('2024-01', $lastfm, $navi);
        $r = $service->compute();

        $gapByYear = [];
        foreach ($r['by_year'] as $row) {
            $gapByYear[$row['year']] = $row['gap'];
        }
        $this->assertSame(600, $gapByYear['2024'] ?? null);
        $this->assertSame(1000, $gapByYear['2025'] ?? null);
        // 2025 has bigger gap → first.
        $this->assertSame('2025', $r['by_year'][0]['year']);
    }

    public function testLastfmMonthsWithoutNavidromeFallbackToZero(): void
    {
        $service = $this->makeService(
            '2024-01',
            lastfmMonths: [['month' => '2024-01', 'plays' => 42]],
            naviMonths: [],
        );
        $r = $service->compute();

        $this->assertCount(1, $r['by_month']);
        $this->assertSame(42, $r['by_month'][0]['gap']);
        $this->assertSame(0, $r['by_month'][0]['navidrome']);
        $this->assertSame(0, $r['by_month'][0]['coverage_pct']);
    }

    public function testTopMonthsCappedAt12(): void
    {
        // 15 months with strictly decreasing gaps to keep the order stable
        // — top should be the 12 highest gaps.
        $lastfm = [];
        $navi = [];
        for ($i = 0; $i < 15; $i++) {
            $month = sprintf('2024-%02d', $i + 1);
            $lastfm[] = ['month' => $month, 'plays' => 1000];
            $navi[] = ['month' => $month, 'plays' => 1000 - (15 - $i) * 10];
        }

        $service = $this->makeService('2024-01', $lastfm, $navi);
        $r = $service->compute();

        $this->assertCount(12, $r['by_month']);
    }
}
