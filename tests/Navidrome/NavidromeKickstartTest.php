<?php

namespace App\Tests\Navidrome;

use App\Navidrome\NavidromeRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for getDailyKickstartTracks(): per-day first scrobble
 * aggregated by track, ranked by how many days the track opened the day.
 */
class NavidromeKickstartTest extends TestCase
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

    public function testRanksTracksByHowOftenTheyOpenTheDay(): void
    {
        $path = sys_get_temp_dir() . '/nd-kick-' . uniqid() . '.db';
        $this->cleanup[] = $path;
        $conn = NavidromeFixtureFactory::createDatabase($path, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-a', 'A', 'Artist');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-b', 'B', 'Artist');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-c', 'C', 'Artist');

        // Day 1: a (09:00) opens, then b later.
        self::play($conn, 'mf-a', '2024-01-01 09:00:00');
        self::play($conn, 'mf-b', '2024-01-01 10:00:00');
        // Day 2: a (08:00) opens, then c later.
        self::play($conn, 'mf-a', '2024-01-02 08:00:00');
        self::play($conn, 'mf-c', '2024-01-02 09:00:00');
        // Day 3: b (07:30) opens.
        self::play($conn, 'mf-b', '2024-01-03 07:30:00');
        $conn->close();

        $repo = new NavidromeRepository($path, 'admin');

        // a opens 2 days, b opens 1 day, c never. c is excluded.
        $this->assertSame(['mf-a', 'mf-b'], $repo->getDailyKickstartTracks(10));
    }

    public function testLimitCapsTheResult(): void
    {
        $path = sys_get_temp_dir() . '/nd-kick-' . uniqid() . '.db';
        $this->cleanup[] = $path;
        $conn = NavidromeFixtureFactory::createDatabase($path, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-a', 'A', 'Artist');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-b', 'B', 'Artist');
        self::play($conn, 'mf-a', '2024-01-01 09:00:00');
        self::play($conn, 'mf-b', '2024-01-02 09:00:00');
        $conn->close();

        $repo = new NavidromeRepository($path, 'admin');

        $this->assertCount(1, $repo->getDailyKickstartTracks(1));
    }

    public function testReturnsEmptyWhenNoScrobblesTable(): void
    {
        $path = sys_get_temp_dir() . '/nd-kick-' . uniqid() . '.db';
        $this->cleanup[] = $path;
        $conn = NavidromeFixtureFactory::createDatabase($path, withScrobbles: false);
        $conn->close();

        $repo = new NavidromeRepository($path, 'admin');

        $this->assertSame([], $repo->getDailyKickstartTracks(10));
    }

    private static function play(Connection $conn, string $mediaId, string $time): void
    {
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', $mediaId, $time);
    }
}
