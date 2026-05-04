<?php

namespace App\Tests\Navidrome;

use App\Navidrome\NavidromeDbBackup;
use PHPUnit\Framework\TestCase;

class NavidromeDbBackupTest extends TestCase
{
    /** @var list<string> */
    private array $createdPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->createdPaths as $path) {
            foreach (['', '-wal', '-shm'] as $suffix) {
                @unlink($path . $suffix);
            }
            foreach (glob($path . '.backup-*') ?: [] as $backup) {
                @unlink($backup);
                @unlink($backup . '-wal');
                @unlink($backup . '-shm');
            }
        }
        $this->createdPaths = [];
    }

    public function testBackupReturnsNullWhenSourceFileMissing(): void
    {
        $backup = new NavidromeDbBackup('/tmp/no-such-file-' . bin2hex(random_bytes(4)) . '.db');
        $this->assertNull($backup->backup());
    }

    public function testBackupCopiesSqliteFile(): void
    {
        $dbPath = $this->makeSqliteDb();
        $backup = new NavidromeDbBackup($dbPath, retention: 5);

        $target = $backup->backup();
        $this->assertNotNull($target);
        $this->assertFileExists($target);
        $this->assertMatchesRegularExpression('/\.backup-\d{14}$/', $target);
        $this->assertSame(file_get_contents($dbPath), file_get_contents($target));
    }

    public function testBackupCopiesWalAndShmSiblingsWhenPresent(): void
    {
        $dbPath = $this->makeSqliteDb();
        // Fake WAL/SHM siblings — we only check copy, not validity.
        file_put_contents($dbPath . '-wal', 'fake wal payload');
        file_put_contents($dbPath . '-shm', 'fake shm payload');

        $target = (new NavidromeDbBackup($dbPath))->backup();

        $this->assertNotNull($target);
        $this->assertFileExists($target . '-wal');
        $this->assertFileExists($target . '-shm');
        $this->assertSame('fake wal payload', file_get_contents($target . '-wal'));
        $this->assertSame('fake shm payload', file_get_contents($target . '-shm'));
    }

    public function testRetentionPrunesOldestBackups(): void
    {
        $dbPath = $this->makeSqliteDb();

        // Plant 4 forged old backups with chronologically-sortable stamps.
        // Doing it this way (vs calling backup() multiple times) lets us
        // skirt the per-second timestamp granularity of `backup()`.
        foreach (['20200101000001', '20200101000002', '20200101000003', '20200101000004'] as $stamp) {
            file_put_contents($dbPath . '.backup-' . $stamp, 'old');
        }

        $backup = new NavidromeDbBackup($dbPath, retention: 2);
        $latest = $backup->backup();
        $this->assertNotNull($latest);

        $remaining = $backup->listBackups();
        $this->assertCount(2, $remaining, 'retention=2 must keep only the 2 most recent backups');
        $this->assertContains($latest, $remaining);
        // The most recent forged backup is the 2nd kept one.
        $this->assertContains($dbPath . '.backup-20200101000004', $remaining);
        // The 3 oldest forged backups must all be gone.
        $this->assertFileDoesNotExist($dbPath . '.backup-20200101000001');
        $this->assertFileDoesNotExist($dbPath . '.backup-20200101000002');
        $this->assertFileDoesNotExist($dbPath . '.backup-20200101000003');
    }

    public function testRetentionZeroKeepsEverything(): void
    {
        $dbPath = $this->makeSqliteDb();

        // Plant 5 forged old backups, retention=0 must leave them all.
        foreach (range(1, 5) as $i) {
            file_put_contents($dbPath . '.backup-2020010100000' . $i, 'old');
        }

        $backup = new NavidromeDbBackup($dbPath, retention: 0);
        $latest = $backup->backup();
        $this->assertNotNull($latest);
        $this->assertGreaterThanOrEqual(6, count($backup->listBackups()));
    }

    public function testQuickCheckPassesOnHealthyDb(): void
    {
        $dbPath = $this->makeSqliteDb();
        (new NavidromeDbBackup($dbPath))->quickCheck();
        $this->addToAssertionCount(1);
    }

    public function testQuickCheckIsNoopWhenFileMissing(): void
    {
        $backup = new NavidromeDbBackup('/tmp/no-such-file-' . bin2hex(random_bytes(4)) . '.db');
        $backup->quickCheck();
        $this->addToAssertionCount(1);
    }

    public function testQuickCheckThrowsOnGarbageFile(): void
    {
        $path = sys_get_temp_dir() . '/nv-bk-bad-' . bin2hex(random_bytes(4)) . '.db';
        file_put_contents($path, str_repeat("\xFF", 4096));
        $this->createdPaths[] = $path;

        $this->expectException(\RuntimeException::class);
        (new NavidromeDbBackup($path))->quickCheck();
    }

    public function testQuickCheckThrowsOnTruncatedSqliteHeader(): void
    {
        // Real SQLite file truncated to 50 bytes — invalid header that
        // SQLite refuses to open. Models the « SIGKILL pendant fsync »
        // case that motivated this whole machinery.
        $dbPath = $this->makeSqliteDb();
        $truncated = sys_get_temp_dir() . '/nv-bk-trunc-' . bin2hex(random_bytes(4)) . '.db';
        file_put_contents($truncated, substr((string) file_get_contents($dbPath), 0, 50));
        $this->createdPaths[] = $truncated;

        $this->expectException(\RuntimeException::class);
        (new NavidromeDbBackup($truncated))->quickCheck();
    }

    private function makeSqliteDb(): string
    {
        $path = sys_get_temp_dir() . '/nv-bk-test-' . bin2hex(random_bytes(4)) . '.db';
        $pdo = new \PDO('sqlite:' . $path);
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
        $pdo->exec("INSERT INTO t (v) VALUES ('one'), ('two')");
        unset($pdo);
        $this->createdPaths[] = $path;

        return $path;
    }
}
