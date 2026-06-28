<?php

namespace App\Tests\Navidrome;

use App\Navidrome\NavidromeRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for getTopTracksOnDayOfYear(): aggregates every play
 * on a given month/day across all years, ranked by play count.
 */
class NavidromeDayOfYearTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            foreach ([$path, $path . '-wal', $path . '-shm'] as $f) {
                if (file_exists($f)) {
                    unlink($f);
                }
            }
        }
    }

    public function testAggregatesPlaysOnTheSameDayAcrossYears(): void
    {
        $path = sys_get_temp_dir() . '/nd-doy-' . uniqid() . '.db';
        $this->cleanup[] = $path;
        $conn = NavidromeFixtureFactory::createDatabase($path, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-a', 'A', 'Artist');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-b', 'B', 'Artist');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-c', 'C', 'Artist');

        // 22 May, two different years → mf-a is the day's top (3 plays).
        self::play($conn, 'mf-a', '2021-05-22 10:00:00');
        self::play($conn, 'mf-a', '2022-05-22 11:00:00');
        self::play($conn, 'mf-a', '2023-05-22 12:00:00');
        self::play($conn, 'mf-b', '2022-05-22 13:00:00');
        // mf-c only ever played on OTHER days → excluded.
        self::play($conn, 'mf-c', '2022-05-23 10:00:00');
        self::play($conn, 'mf-c', '2022-06-22 10:00:00');
        $conn->close();

        $repo = new NavidromeRepository($path, 'admin');

        $this->assertSame(['mf-a', 'mf-b'], $repo->getTopTracksOnDayOfYear(5, 22, 10));
    }

    public function testReturnsEmptyWhenNoScrobblesTable(): void
    {
        $path = sys_get_temp_dir() . '/nd-doy-' . uniqid() . '.db';
        $this->cleanup[] = $path;
        $conn = NavidromeFixtureFactory::createDatabase($path, withScrobbles: false);
        $conn->close();

        $repo = new NavidromeRepository($path, 'admin');

        $this->assertSame([], $repo->getTopTracksOnDayOfYear(5, 22, 10));
    }

    private static function play(Connection $conn, string $mediaId, string $time): void
    {
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', $mediaId, $time);
    }
}
