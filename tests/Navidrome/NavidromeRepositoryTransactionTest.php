<?php

namespace App\Tests\Navidrome;

use App\Navidrome\NavidromeRepository;
use PHPUnit\Framework\TestCase;

/**
 * Covers the durability/concurrency knobs added to {@see NavidromeRepository}
 * for crash-safe scrobble imports : explicit transaction helpers, PRAGMA
 * setup, WAL checkpoint, connection close.
 */
class NavidromeRepositoryTransactionTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/navidrome-tx-test-' . uniqid() . '.db';
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            $path = $this->dbPath . $suffix;
            if (file_exists($path)) {
                @unlink($path);
            }
        }
    }

    public function testCommitWritePersistsBatchedInserts(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Track 1');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-2', 'Track 2');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-3', 'Track 3');
        $conn->close();

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $repo->beginWriteTransaction();
        $repo->insertScrobble('user-1', 'mf-1', new \DateTimeImmutable('2026-01-01 10:00:00'));
        $repo->insertScrobble('user-1', 'mf-2', new \DateTimeImmutable('2026-01-01 10:01:00'));
        $repo->insertScrobble('user-1', 'mf-3', new \DateTimeImmutable('2026-01-01 10:02:00'));
        $repo->commitWrite();
        $repo->closeWriteConnection();

        $this->assertSame(3, $this->countScrobbles());
    }

    public function testRollbackWriteDiscardsBatchedInserts(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Track 1');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-2', 'Track 2');
        $conn->close();

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $repo->beginWriteTransaction();
        $repo->insertScrobble('user-1', 'mf-1', new \DateTimeImmutable('2026-01-01 10:00:00'));
        $repo->insertScrobble('user-1', 'mf-2', new \DateTimeImmutable('2026-01-01 10:01:00'));
        // Crash mid-batch — rollback must discard everything written so far.
        $repo->rollbackWrite();
        $repo->closeWriteConnection();

        $this->assertSame(0, $this->countScrobbles(), 'rollback must discard the entire batch');
    }

    public function testBeginImmediateFailsFastWhenAnotherWriterHoldsTheLock(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Track 1');
        $conn->close();

        // Open a competing PDO writer and grab the RESERVED lock first.
        // Use a *short* busy_timeout on the second connection so the test
        // doesn't wait the full 30s — the helper opens a fresh PDO with its
        // own knobs that override the repo defaults for this test only.
        $other = new \PDO('sqlite:' . $this->dbPath, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        $other->exec('BEGIN IMMEDIATE');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        // Override busy_timeout for this test only — repo default 30s is too
        // long for a unit test.
        $repo->isAvailable(); // forces connection open + PRAGMA setup
        $native = $this->reflectionPdo($repo);
        $native->exec('PRAGMA busy_timeout = 100');

        try {
            $repo->beginWriteTransaction();
            $this->fail('beginWriteTransaction must throw when another writer holds the lock');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('database is locked', strtolower($e->getMessage()));
        } finally {
            $other->exec('ROLLBACK');
            $other = null;
            $repo->closeWriteConnection();
        }
    }

    public function testWalCheckpointTruncateIsNoOpWhenConnectionNotOpen(): void
    {
        $repo = new NavidromeRepository($this->dbPath, 'admin');
        // No exception even though the DB file doesn't exist and no connection
        // has been opened.
        $repo->walCheckpointTruncate();
        $this->addToAssertionCount(1);
    }

    public function testWalCheckpointTruncateRunsSilentlyOnDeleteJournal(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Track 1');
        $conn->close();

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $repo->beginWriteTransaction();
        $repo->insertScrobble('user-1', 'mf-1', new \DateTimeImmutable('2026-01-01 10:00:00'));
        $repo->commitWrite();
        // The fixture DB is in DELETE journal mode (default). Checkpoint
        // must just no-op without throwing.
        $repo->walCheckpointTruncate();
        $repo->closeWriteConnection();
        $this->addToAssertionCount(1);
    }

    public function testConnectionAppliesBusyTimeoutAndSynchronous(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath);
        $conn->close();

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $repo->isAvailable(); // open the connection + apply pragmas

        $native = $this->reflectionPdo($repo);
        $busyTimeout = (int) $native->query('PRAGMA busy_timeout')->fetchColumn();
        $synchronous = (int) $native->query('PRAGMA synchronous')->fetchColumn();

        $this->assertSame(30000, $busyTimeout, 'busy_timeout must be 30000ms for crash resilience');
        // synchronous = 2 = FULL (full fsync on commit).
        $this->assertSame(2, $synchronous, 'synchronous must be FULL on the writer connection');
    }

    public function testCloseWriteConnectionAllowsLazyReconnect(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Track 1');
        $conn->close();

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $repo->beginWriteTransaction();
        $repo->insertScrobble('user-1', 'mf-1', new \DateTimeImmutable('2026-01-01 10:00:00'));
        $repo->commitWrite();
        $repo->closeWriteConnection();

        // After a close, subsequent calls must transparently reconnect.
        $this->assertTrue($repo->isAvailable(), 'connection must lazily re-open after closeWriteConnection');
        $this->assertSame(1, $this->countScrobbles());
    }

    private function countScrobbles(): int
    {
        $pdo = new \PDO('sqlite:' . $this->dbPath, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
        $count = $pdo->query('SELECT COUNT(*) FROM scrobbles')->fetchColumn();

        return (int) $count;
    }

    /**
     * Reach into the repo's private connection to drive raw PDO queries.
     * Only used in tests that need to inspect SQLite session state.
     */
    private function reflectionPdo(NavidromeRepository $repo): \PDO
    {
        $ref = new \ReflectionClass($repo);
        $prop = $ref->getProperty('connection');
        $prop->setAccessible(true);
        $connection = $prop->getValue($repo);
        $this->assertNotNull($connection, 'expected an open Doctrine connection');
        $native = $connection->getNativeConnection();
        $this->assertInstanceOf(\PDO::class, $native);

        return $native;
    }
}
