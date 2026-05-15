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
