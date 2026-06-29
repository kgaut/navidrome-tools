<?php

namespace App\Tests\Navidrome;

use App\Navidrome\NavidromeRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for getPlayCountsByMediaFileId(): lifetime play count per
 * media_file id, 0 for never-played, with a scrobbles path and an
 * annotation-only fallback.
 */
class NavidromePlayCountsTest extends TestCase
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

    public function testCountsFromScrobblesAndZeroForNeverPlayed(): void
    {
        $path = sys_get_temp_dir() . '/nd-pc-' . uniqid() . '.db';
        $this->cleanup[] = $path;
        $conn = NavidromeFixtureFactory::createDatabase($path, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-a', 'A', 'Artist');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-b', 'B', 'Artist');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-c', 'C', 'Artist');

        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-a', '2024-01-01 09:00:00');
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-a', '2024-01-02 09:00:00');
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-a', '2024-01-03 09:00:00');
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-b', '2024-01-01 10:00:00');
        $conn->close();

        $repo = new NavidromeRepository($path, 'admin');

        // mf-c never played, mf-x not even in library → both 0; all keys present.
        $counts = $repo->getPlayCountsByMediaFileId(['mf-a', 'mf-b', 'mf-c', 'mf-x']);

        $this->assertSame(['mf-a' => 3, 'mf-b' => 1, 'mf-c' => 0, 'mf-x' => 0], $counts);
    }

    public function testFallsBackToAnnotationPlayCount(): void
    {
        $path = sys_get_temp_dir() . '/nd-pc-' . uniqid() . '.db';
        $this->cleanup[] = $path;
        $conn = NavidromeFixtureFactory::createDatabase($path, withScrobbles: false);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-a', 'A', 'Artist');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-b', 'B', 'Artist');
        NavidromeFixtureFactory::insertAnnotation($conn, 'user-1', 'mf-a', 7, '2024-01-03 09:00:00');
        $conn->close();

        $repo = new NavidromeRepository($path, 'admin');

        $counts = $repo->getPlayCountsByMediaFileId(['mf-a', 'mf-b']);

        $this->assertSame(['mf-a' => 7, 'mf-b' => 0], $counts);
    }

    public function testEmptyInputReturnsEmpty(): void
    {
        $path = sys_get_temp_dir() . '/nd-pc-' . uniqid() . '.db';
        $this->cleanup[] = $path;
        $conn = NavidromeFixtureFactory::createDatabase($path, withScrobbles: true);
        $conn->close();

        $repo = new NavidromeRepository($path, 'admin');

        $this->assertSame([], $repo->getPlayCountsByMediaFileId([]));
    }
}
