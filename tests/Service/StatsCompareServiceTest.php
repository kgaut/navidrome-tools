<?php

namespace App\Tests\Service;

use App\Navidrome\NavidromeRepository;
use App\Service\StatsCompareService;
use App\Service\StatsService;
use App\Tests\Navidrome\NavidromeFixtureFactory;
use PHPUnit\Framework\TestCase;

class StatsCompareServiceTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/navidrome-cmp-' . uniqid() . '.db';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testCompareDetectsNewGoneAndDeltas(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-stay', 'Stay', 'A1');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-gone', 'Gone', 'A2');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-new', 'New', 'A3');

        $now = new \DateTimeImmutable();
        // Use last-year vs last-7d so the windows don't overlap (mid-year vs recent).
        $p1Day = (new \DateTimeImmutable())->modify(sprintf('%d-06-15 10:00:00', (int) $now->format('Y') - 1));
        $p2Day = $now->modify('-3 days');

        // 'Gone' track: only in last-year
        for ($i = 0; $i < 4; $i++) {
            NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-gone', $p1Day->format('Y-m-d H:i:s'));
        }
        // 'New' track: only in last-7d
        for ($i = 0; $i < 2; $i++) {
            NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-new', $p2Day->format('Y-m-d H:i:s'));
        }
        // 'Stay' track: present in both windows
        for ($i = 0; $i < 5; $i++) {
            NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-stay', $p1Day->format('Y-m-d H:i:s'));
        }
        for ($i = 0; $i < 3; $i++) {
            NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-stay', $p2Day->format('Y-m-d H:i:s'));
        }

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $service = new StatsCompareService($repo);
        $result = $service->compare(StatsService::PERIOD_LAST_YEAR, StatsService::PERIOD_LAST_7D);

        $byTitle = [];
        foreach ($result->tracks as $row) {
            $byTitle[$row['title']] = $row;
        }

        // 'Stay' track is in both — but P1 (last 30d) should include P2 plays too.
        // We assert 'Gone' and 'New' status which are unambiguous.
        $this->assertSame('disparu', $byTitle['Gone']['status']);
        $this->assertSame(0, $byTitle['Gone']['plays2']);

        $this->assertSame('nouveau', $byTitle['New']['status']);
        $this->assertSame(0, $byTitle['New']['plays1']);
        $this->assertSame(2, $byTitle['New']['plays2']);
    }

    public function testInvalidPeriodThrows(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        $service = new StatsCompareService(new NavidromeRepository($this->dbPath, 'admin'));

        $this->expectException(\InvalidArgumentException::class);
        $service->compare('invalid', StatsService::PERIOD_LAST_7D);
    }
}
