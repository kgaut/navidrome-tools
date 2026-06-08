<?php

namespace App\Tests\Navidrome;

use App\Navidrome\NavidromeRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Locks in the behaviour of the top-* readers that feed the 6 Top pages:
 * ranking by play volume, first/last play bounds, and the year/month/day
 * cascade filter. These are integration tests against a real SQLite
 * Navidrome fixture — the SQL (GROUP BY + strftime on submission_time
 * unixepoch) is the part with real bug surface.
 */
class NavidromeTopListsTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/nd-top-test-' . uniqid() . '.db';
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);

        // Three artists / albums / tracks with distinct play volumes and
        // spread across two years so the cascade can be exercised.
        NavidromeFixtureFactory::insertTrack($conn, 'mf-a1', 'Around the World', 'Daft Punk', album: 'Homework');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-a2', 'Da Funk', 'Daft Punk', album: 'Homework');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-b1', 'Hoppipolla', 'Sigur Ros', album: 'Takk');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-c1', 'Svefn', 'Amiina', album: 'Kurr');

        // Daft Punk: 4 plays (3 in 2024-03, 1 in 2025-01) — top artist.
        self::play($conn, 'mf-a1', '2024-03-01 10:00:00');
        self::play($conn, 'mf-a1', '2024-03-15 11:00:00');
        self::play($conn, 'mf-a2', '2024-03-20 12:00:00');
        self::play($conn, 'mf-a1', '2025-01-05 09:00:00');
        // Sigur Ros: 2 plays in 2024-03.
        self::play($conn, 'mf-b1', '2024-03-02 10:00:00');
        self::play($conn, 'mf-b1', '2024-03-03 10:00:00');
        // Amiina: 1 play in 2025-02.
        self::play($conn, 'mf-c1', '2025-02-10 10:00:00');

        $conn->close();
    }

    protected function tearDown(): void
    {
        foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $f) {
            if (file_exists($f)) {
                unlink($f);
            }
        }
    }

    private static function play(Connection $conn, string $mediaId, string $time): void
    {
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', $mediaId, $time);
    }

    private function repo(): NavidromeRepository
    {
        return new NavidromeRepository($this->dbPath, 'admin');
    }

    public function testTopArtistsRankedByPlaysWithFirstLastBounds(): void
    {
        $rows = $this->repo()->getTopArtistsWithDates(null, null, null, 10);

        $this->assertSame('Daft Punk', $rows[0]['artist']);
        $this->assertSame(4, $rows[0]['plays']);
        $this->assertSame('Sigur Ros', $rows[1]['artist']);
        $this->assertSame(2, $rows[1]['plays']);
        // First / last play span the full history for Daft Punk.
        $this->assertSame('2024-03-01 10:00:00', $rows[0]['first_played_at']);
        $this->assertSame('2025-01-05 09:00:00', $rows[0]['last_played_at']);
    }

    public function testTopArtistsYearFilter(): void
    {
        $rows = $this->repo()->getTopArtistsWithDates(2025, null, null, 10);

        $byArtist = array_column($rows, 'plays', 'artist');
        $this->assertSame(1, $byArtist['Daft Punk'] ?? null, '2025 has 1 Daft Punk play');
        $this->assertSame(1, $byArtist['Amiina'] ?? null);
        $this->assertArrayNotHasKey('Sigur Ros', $byArtist, 'Sigur Ros never played in 2025');
    }

    public function testTopArtistsYearMonthFilter(): void
    {
        $rows = $this->repo()->getTopArtistsWithDates(2024, 3, null, 10);
        $byArtist = array_column($rows, 'plays', 'artist');

        $this->assertSame(3, $byArtist['Daft Punk'] ?? null);
        $this->assertSame(2, $byArtist['Sigur Ros'] ?? null);
        $this->assertArrayNotHasKey('Amiina', $byArtist);
    }

    public function testTopArtistsFullDayFilter(): void
    {
        $rows = $this->repo()->getTopArtistsWithDates(2024, 3, 1, 10);
        $byArtist = array_column($rows, 'plays', 'artist');

        $this->assertSame(['Daft Punk' => 1], $byArtist, 'Only mf-a1 on 2024-03-01');
    }

    public function testTopAlbumsRankedByPlays(): void
    {
        $rows = $this->repo()->getTopAlbumsWithDates(null, null, null, 10);

        $this->assertSame('Homework', $rows[0]['album']);
        $this->assertSame(4, $rows[0]['plays']);
        $this->assertSame(2, $rows[0]['track_count'], 'Homework has 2 distinct tracks played');
    }

    public function testTopTracksRankedByPlays(): void
    {
        $rows = $this->repo()->getTopTracksWithDates(null, null, null, 10);

        $this->assertSame('mf-a1', $rows[0]['id']);
        $this->assertSame('Around the World', $rows[0]['title']);
        $this->assertSame(3, $rows[0]['plays']);
        $this->assertSame('2024-03-01 10:00:00', $rows[0]['first_played_at']);
        $this->assertSame('2025-01-05 09:00:00', $rows[0]['last_played_at']);
    }

    public function testLimitCapsResults(): void
    {
        $this->assertCount(1, $this->repo()->getTopArtistsWithDates(null, null, null, 1));
    }

    public function testEmptyWhenScrobblesTableMissing(): void
    {
        $path = sys_get_temp_dir() . '/nd-noscrob-' . uniqid() . '.db';
        $conn = NavidromeFixtureFactory::createDatabase($path, withScrobbles: false);
        $conn->close();

        $repo = new NavidromeRepository($path, 'admin');
        $this->assertSame([], $repo->getTopArtistsWithDates(null, null, null, 10));
        $this->assertSame([], $repo->getTopAlbumsWithDates(null, null, null, 10));
        $this->assertSame([], $repo->getTopTracksWithDates(null, null, null, 10));
        $this->assertSame([], $repo->getAvailableScrobbleYears());

        unlink($path);
    }

    public function testAvailableScrobbleYearsDescending(): void
    {
        $this->assertSame(['2025', '2024'], $this->repo()->getAvailableScrobbleYears());
    }

    public function testFirstScrobbleMonth(): void
    {
        $this->assertSame('2024-03', $this->repo()->getFirstScrobbleMonth());
    }
}
