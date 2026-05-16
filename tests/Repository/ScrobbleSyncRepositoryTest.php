<?php

namespace App\Tests\Repository;

use App\Entity\ScrobbleSync;
use App\Repository\ScrobbleSyncRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

class ScrobbleSyncRepositoryTest extends TestCase
{
    private string $dbPath;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/scrobble-sync-test-' . uniqid() . '.db';
        $this->conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->dbPath]);
        $this->conn->executeStatement(<<<'SQL'
            CREATE TABLE scrobbles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                lastfm_user VARCHAR(255) NOT NULL,
                artist VARCHAR(255) NOT NULL,
                title VARCHAR(255) NOT NULL,
                played_at DATETIME NOT NULL
            )
        SQL);
        $this->conn->executeStatement(<<<'SQL'
            CREATE TABLE scrobble_sync (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                scrobble_id INTEGER NOT NULL,
                target VARCHAR(32) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'pending'
            )
        SQL);
    }

    protected function tearDown(): void
    {
        $this->conn->close();
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testPendingCountIncludesScrobblesWithoutSyncRow(): void
    {
        // 3 scrobbles fetched, none prepared yet — pending count must
        // reflect the un-prepared rows, otherwise the user sees "0 pending"
        // right after a fetch and assumes nothing needs syncing.
        $this->insertScrobble(1, '2024-01-01 12:00:00');
        $this->insertScrobble(2, '2024-01-02 12:00:00');
        $this->insertScrobble(3, '2024-01-03 12:00:00');

        $count = ScrobbleSyncRepository::queryPendingCount($this->conn, ScrobbleSync::TARGET_NAVIDROME);

        $this->assertSame(3, $count);
    }

    public function testPendingCountSumsUnpreparedAndPendingRows(): void
    {
        $this->insertScrobble(1, '2024-01-01 12:00:00');
        $this->insertScrobble(2, '2024-01-02 12:00:00');
        $this->insertScrobble(3, '2024-01-03 12:00:00');

        // Row 1 prepared and still pending, row 2 unprepared, row 3
        // already matched — expect 1 + 1 = 2 pending.
        $this->insertSync(1, ScrobbleSync::TARGET_NAVIDROME, ScrobbleSync::STATUS_PENDING);
        $this->insertSync(3, ScrobbleSync::TARGET_NAVIDROME, ScrobbleSync::STATUS_MATCHED);

        $count = ScrobbleSyncRepository::queryPendingCount($this->conn, ScrobbleSync::TARGET_NAVIDROME);

        $this->assertSame(2, $count);
    }

    public function testPendingCountIsTargetScoped(): void
    {
        // A scrobble already synced to strawberry must still count as
        // pending for navidrome (each target has its own sync state).
        $this->insertScrobble(1, '2024-01-01 12:00:00');
        $this->insertSync(1, ScrobbleSync::TARGET_STRAWBERRY, ScrobbleSync::STATUS_MATCHED);

        $count = ScrobbleSyncRepository::queryPendingCount($this->conn, ScrobbleSync::TARGET_NAVIDROME);

        $this->assertSame(1, $count);
    }

    private function insertScrobble(int $id, string $playedAt): void
    {
        $this->conn->executeStatement(
            'INSERT INTO scrobbles (id, lastfm_user, artist, title, played_at) VALUES (?, ?, ?, ?, ?)',
            [$id, 'alice', 'Artist ' . $id, 'Title ' . $id, $playedAt],
        );
    }

    private function insertSync(int $scrobbleId, string $target, string $status): void
    {
        $this->conn->executeStatement(
            'INSERT INTO scrobble_sync (scrobble_id, target, status) VALUES (?, ?, ?)',
            [$scrobbleId, $target, $status],
        );
    }
}
