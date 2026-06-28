<?php

namespace App\Tests\Navidrome;

use App\Navidrome\NavidromeRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for getRecentlyDiscoveredTracks() (first-heard window)
 * and getMostConsistentTracks() (distinct-days ranking).
 */
class NavidromeDiscoveryConsistencyTest extends TestCase
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

    public function testRecentlyDiscoveredKeepsOnlyTracksFirstHeardInWindow(): void
    {
        $conn = $this->db($path);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-new', 'New', 'Artist');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-old', 'Old', 'Artist');
        // mf-new: first (and only) heard inside the window.
        self::play($conn, 'mf-new', '2024-03-10 10:00:00');
        // mf-old: first heard BEFORE the window (even if replayed inside it).
        self::play($conn, 'mf-old', '2020-01-01 10:00:00');
        self::play($conn, 'mf-old', '2024-03-12 10:00:00');
        $conn->close();

        $repo = new NavidromeRepository($path, 'admin');
        $since = new \DateTimeImmutable('2024-03-01 00:00:00');

        $this->assertSame(['mf-new'], $repo->getRecentlyDiscoveredTracks($since, 10));
    }

    public function testRecentlyDiscoveredEmptyWhenNoScrobblesTable(): void
    {
        $path = sys_get_temp_dir() . '/nd-disc-' . uniqid() . '.db';
        $this->cleanup[] = $path;
        $conn = NavidromeFixtureFactory::createDatabase($path, withScrobbles: false);
        $conn->close();

        $repo = new NavidromeRepository($path, 'admin');
        $this->assertSame([], $repo->getRecentlyDiscoveredTracks(new \DateTimeImmutable(), 10));
    }

    public function testMostConsistentRanksByDistinctDaysNotVolume(): void
    {
        $conn = $this->db($path);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-regular', 'Regular', 'Artist');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-binge', 'Binge', 'Artist');
        // mf-regular: 3 distinct days, 1 play each.
        self::play($conn, 'mf-regular', '2024-01-01 09:00:00');
        self::play($conn, 'mf-regular', '2024-01-05 09:00:00');
        self::play($conn, 'mf-regular', '2024-01-09 09:00:00');
        // mf-binge: 10 plays but all on the SAME day → 1 distinct day.
        for ($h = 0; $h < 10; $h++) {
            self::play($conn, 'mf-binge', sprintf('2024-02-02 %02d:00:00', $h));
        }
        $conn->close();

        $repo = new NavidromeRepository($path, 'admin');

        // Regular (3 days) ranks above binge (1 day) despite fewer plays.
        $this->assertSame(['mf-regular', 'mf-binge'], $repo->getMostConsistentTracks(10));
    }

    public function testMostConsistentEmptyWhenNoScrobblesTable(): void
    {
        $path = sys_get_temp_dir() . '/nd-cons-' . uniqid() . '.db';
        $this->cleanup[] = $path;
        $conn = NavidromeFixtureFactory::createDatabase($path, withScrobbles: false);
        $conn->close();

        $repo = new NavidromeRepository($path, 'admin');
        $this->assertSame([], $repo->getMostConsistentTracks(10));
    }

    private function db(?string &$path): Connection
    {
        $path = sys_get_temp_dir() . '/nd-disccons-' . uniqid() . '.db';
        $this->cleanup[] = $path;

        return NavidromeFixtureFactory::createDatabase($path, withScrobbles: true);
    }

    private static function play(Connection $conn, string $mediaId, string $time): void
    {
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', $mediaId, $time);
    }
}
