<?php

namespace App\Tests\Service;

use App\Repository\StatsSnapshotRepository;
use App\Service\LocalStatsService;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class LocalStatsServiceTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/stats-test-' . uniqid() . '.db';
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->dbPath]);
        $conn->executeStatement(<<<'SQL'
            CREATE TABLE scrobbles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                lastfm_user VARCHAR(255) NOT NULL,
                artist VARCHAR(255) NOT NULL,
                title VARCHAR(255) NOT NULL,
                album VARCHAR(255),
                played_at DATETIME NOT NULL
            )
        SQL);
        // Insert test data.
        $rows = [
            ['alice', 'Daft Punk', 'Get Lucky', '2024-01-05 12:00:00'],
            ['alice', 'Daft Punk', 'Harder Better Faster', '2024-01-06 12:00:00'],
            ['alice', 'Radiohead', 'Creep', '2024-01-07 12:00:00'],
            ['alice', 'Daft Punk', 'Get Lucky', '2024-01-08 12:00:00'],
        ];
        foreach ($rows as [$user, $artist, $title, $playedAt]) {
            $conn->executeStatement(
                'INSERT INTO scrobbles (lastfm_user, artist, title, played_at) VALUES (?, ?, ?, ?)',
                [$user, $artist, $title, $playedAt],
            );
        }
        $conn->close();
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testComputeAllTimeReturnsCorrectTopArtists(): void
    {
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->dbPath]);
        $snapshots = $this->createMock(StatsSnapshotRepository::class);
        $snapshots->method('findOneByPeriod')->willReturn(null);
        $snapshots->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist');
        $em->method('flush');

        $service = new LocalStatsService($conn, $snapshots, $em);
        $data = $service->compute(LocalStatsService::PERIOD_ALL_TIME, 'alice');

        $this->assertSame(4, $data['total_plays']);
        $this->assertSame('Daft Punk', $data['top_artists'][0]['artist']);
        $this->assertSame(3, (int) $data['top_artists'][0]['plays']);
        $this->assertSame('Radiohead', $data['top_artists'][1]['artist']);

        $conn->close();
    }

    public function testHeatmapCoversFullYearAlignedToMonday(): void
    {
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->dbPath]);
        $service = $this->makeService($conn);

        $heatmap = $service->heatmap('alice');

        // Start must be a Monday — that's what the day-of-week labels rely on.
        $startDow = (int) (new \DateTimeImmutable($heatmap['start']))->format('w');
        $this->assertSame(1, $startDow, 'heatmap start day must be Monday');

        // Window spans 366..372 days: HEATMAP_DAYS=365 + 0..6 snap-to-Monday
        // + 1 (today is inclusive). Hits 372 when today itself is a Monday.
        $start = new \DateTimeImmutable($heatmap['start']);
        $end = new \DateTimeImmutable($heatmap['end']);
        $days = $start->diff($end)->days + 1;
        $this->assertGreaterThanOrEqual(366, $days);
        $this->assertLessThanOrEqual(372, $days);

        // Each week is exactly 7 cells (Monday..Sunday), null-padded after today.
        foreach ($heatmap['weeks'] as $week) {
            $this->assertCount(7, $week);
        }

        $conn->close();
    }

    public function testHeatmapLevelsUseQuartileBucketsOfNonZeroDays(): void
    {
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->dbPath]);
        // Wipe the setUp fixtures (old 2024 dates fall outside the rolling
        // 365d window so they're irrelevant — but make it explicit).
        $conn->executeStatement('DELETE FROM scrobbles');

        // 4 distinct non-zero counts: 1, 5, 10, 50 — quartiles at 1, 5, 10.
        $today = new \DateTimeImmutable('today');
        $this->insertN($conn, $today->modify('-1 day'), 1);
        $this->insertN($conn, $today->modify('-2 days'), 5);
        $this->insertN($conn, $today->modify('-3 days'), 10);
        $this->insertN($conn, $today->modify('-4 days'), 50);

        $service = $this->makeService($conn);
        $heatmap = $service->heatmap('alice');

        $this->assertSame([1, 5, 10], $heatmap['thresholds']);
        $this->assertSame(50, $heatmap['max']);
        $this->assertSame(66, $heatmap['total']);

        // Flatten and check each level assignment.
        $byDate = [];
        foreach ($heatmap['weeks'] as $week) {
            foreach ($week as $cell) {
                if ($cell !== null) {
                    $byDate[$cell['date']] = $cell['level'];
                }
            }
        }
        $this->assertSame(1, $byDate[$today->modify('-1 day')->format('Y-m-d')]); // 1 plays → q1
        $this->assertSame(2, $byDate[$today->modify('-2 days')->format('Y-m-d')]); // 5 plays → q2
        $this->assertSame(3, $byDate[$today->modify('-3 days')->format('Y-m-d')]); // 10 plays → q3
        $this->assertSame(4, $byDate[$today->modify('-4 days')->format('Y-m-d')]); // 50 plays → >q3

        $conn->close();
    }

    private function makeService(\Doctrine\DBAL\Connection $conn): LocalStatsService
    {
        $snapshots = $this->createMock(StatsSnapshotRepository::class);
        $snapshots->method('findOneByPeriod')->willReturn(null);
        $em = $this->createMock(EntityManagerInterface::class);

        return new LocalStatsService($conn, $snapshots, $em);
    }

    private function insertN(\Doctrine\DBAL\Connection $conn, \DateTimeImmutable $day, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $conn->executeStatement(
                'INSERT INTO scrobbles (lastfm_user, artist, title, played_at) VALUES (?, ?, ?, ?)',
                ['alice', 'A', 'T' . $i, $day->modify(sprintf('+%d minutes', $i))->format('Y-m-d H:i:s')],
            );
        }
    }

    public function testTopTracksGroupsByArtistAndTitle(): void
    {
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->dbPath]);
        $snapshots = $this->createMock(StatsSnapshotRepository::class);
        $snapshots->method('findOneByPeriod')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist');
        $em->method('flush');

        $service = new LocalStatsService($conn, $snapshots, $em);
        $data = $service->compute(LocalStatsService::PERIOD_ALL_TIME, 'alice');

        $topTrack = $data['top_tracks'][0];
        $this->assertSame('Get Lucky', $topTrack['title']);
        $this->assertSame(2, (int) $topTrack['plays']);

        $conn->close();
    }
}
