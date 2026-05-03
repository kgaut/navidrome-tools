<?php

namespace App\Tests\Navidrome;

use App\Navidrome\NavidromeRepository;
use PHPUnit\Framework\TestCase;

class NavidromeRepositoryTimeSeriesTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/navidrome-ts-' . uniqid() . '.db';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testGetPlaysByMonthFillsZeros(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'A');

        $now = new \DateTimeImmutable();
        // Play once in current month, twice 2 months ago, never 1 month ago.
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', $now->format('Y-m-d 12:00:00'));
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', $now->modify('-2 months')->format('Y-m-d 12:00:00'));
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', $now->modify('-2 months')->format('Y-m-d 18:00:00'));

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $series = $repo->getPlaysByMonth(3);

        $this->assertCount(3, $series);
        $this->assertSame(2, $series[0]['plays'], 'oldest month');
        $this->assertSame(0, $series[1]['plays'], 'middle month is filled with 0');
        $this->assertSame(1, $series[2]['plays'], 'current month');
    }

    public function testGetDiversityByMonthCountsUniqueArtists(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'A', 'Daft Punk');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-2', 'B', 'Aphex Twin');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-3', 'C', 'Daft Punk');

        $now = new \DateTimeImmutable();
        // current month: 3 plays / 2 unique artists
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', $now->format('Y-m-d 10:00:00'));
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-3', $now->format('Y-m-d 11:00:00'));
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-2', $now->format('Y-m-d 12:00:00'));
        // 2 months ago: 2 plays / 1 unique artist
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', $now->modify('-2 months')->format('Y-m-d 10:00:00'));
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-3', $now->modify('-2 months')->format('Y-m-d 11:00:00'));

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $series = $repo->getDiversityByMonth(3);

        $this->assertCount(3, $series);
        $this->assertSame(2, $series[0]['plays']);
        $this->assertSame(1, $series[0]['uniques'], 'two months ago: only Daft Punk');
        $this->assertSame(0, $series[1]['plays']);
        $this->assertSame(0, $series[1]['uniques']);
        $this->assertSame(3, $series[2]['plays']);
        $this->assertSame(2, $series[2]['uniques'], 'current month: Daft Punk + Aphex Twin');
    }

    public function testGetTopArtistsTimelineRanksAndExposesFullSeries(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'T1', 'Daft Punk');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-2', 'T2', 'Aphex Twin');

        $now = new \DateTimeImmutable();
        for ($i = 0; $i < 5; $i++) {
            NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', $now->format('Y-m-d 10:00:00'));
        }
        for ($i = 0; $i < 3; $i++) {
            NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-2', $now->format('Y-m-d 10:00:00'));
        }

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $top = $repo->getTopArtistsTimeline(3, 5);

        $this->assertSame('Daft Punk', $top[0]['artist']);
        $this->assertSame(5, $top[0]['total']);
        $this->assertCount(3, $top[0]['series'], 'one entry per month over the window');
    }

    public function testGetHeatmapDayHourReturns7x24Matrix(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'A');

        // Insert two scrobbles known to be on Monday 09:00 and 09:30
        $monday = new \DateTimeImmutable('2025-01-06 09:00:00'); // 2025-01-06 was a Monday
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', $monday->format('Y-m-d H:i:s'));
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', $monday->modify('+30 minutes')->format('Y-m-d H:i:s'));

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $matrix = $repo->getHeatmapDayHour(null, null);

        $this->assertCount(7, $matrix);
        $this->assertCount(24, $matrix[0]);
        $this->assertSame(2, $matrix[1][9], 'Monday 9h cell holds the two plays');
        $this->assertSame(0, $matrix[0][9], 'Sunday 9h cell stays empty');
    }

    public function testGetDailyPlaysCoversFullYear(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'A');
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', '2024-03-15 10:00:00');
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', '2024-03-15 11:00:00');
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', '2024-12-31 23:30:00');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $daily = $repo->getDailyPlays(2024);

        $this->assertCount(366, $daily, '2024 is a leap year');
        $this->assertSame(2, $daily['2024-03-15']);
        $this->assertSame(1, $daily['2024-12-31']);
        $this->assertSame(0, $daily['2024-06-01']);
        $this->assertArrayNotHasKey('2025-01-01', $daily, 'next year not included');
    }

    public function testGetNewArtistsOnlyReturnsArtistsWithFirstPlayInYear(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-old', 'Track', 'Old Artist');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-new', 'Track', 'New Artist');

        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-old', '2023-06-01 10:00:00');
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-old', '2024-06-01 10:00:00');
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-new', '2024-09-15 10:00:00');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $new = $repo->getNewArtists(2024);

        $this->assertCount(1, $new);
        $this->assertSame('New Artist', $new[0]['artist']);
    }

    public function testGetLongestListeningStreakDetectsConsecutiveDays(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'A');

        // 3 jours consécutifs
        foreach (['2024-04-10', '2024-04-11', '2024-04-12'] as $d) {
            NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', $d . ' 10:00:00');
        }
        // trou
        // 5 jours consécutifs (record)
        foreach (['2024-08-01', '2024-08-02', '2024-08-03', '2024-08-04', '2024-08-05'] as $d) {
            NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', $d . ' 10:00:00');
        }

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $this->assertSame(5, $repo->getLongestListeningStreak(2024));
    }

    public function testGetMostActiveMonth(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'A');

        for ($i = 0; $i < 4; $i++) {
            NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', '2024-03-' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) . ' 10:00:00');
        }
        for ($i = 0; $i < 7; $i++) {
            NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', '2024-08-' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) . ' 10:00:00');
        }

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $top = $repo->getMostActiveMonth(2024);
        $this->assertSame(['month' => '2024-08', 'plays' => 7], $top);
    }
}
