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

    public function testRestoreLatestWhenTimestampNull(): void
    {
        $dbPath = $this->makeSqliteDb();
        $backup = new NavidromeDbBackup($dbPath, retention: 5);

        $backupPath = $backup->backup();
        $this->assertNotNull($backupPath);
        $originalContent = (string) file_get_contents($dbPath);

        // Mutate the live DB — restore must overwrite this with the snapshot.
        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->exec("INSERT INTO t (v) VALUES ('mutated-after-backup')");
        unset($pdo);

        $restoredFrom = $backup->restore();
        $this->assertSame($backupPath, $restoredFrom);
        $this->assertSame($originalContent, file_get_contents($dbPath));
    }

    public function testRestoreSpecificTimestamp(): void
    {
        $dbPath = $this->makeSqliteDb();
        $backup = new NavidromeDbBackup($dbPath, retention: 5);

        // Plant two SQLite-valid forged backups with distinguishable content.
        $oldStamp = '20200101000001';
        $oldBackup = $dbPath . '.backup-' . $oldStamp;
        $this->makeSqliteDbAt($oldBackup, "INSERT INTO t (v) VALUES ('from-old-backup')");

        $newerStamp = '20200101000002';
        $newerBackup = $dbPath . '.backup-' . $newerStamp;
        $this->makeSqliteDbAt($newerBackup, "INSERT INTO t (v) VALUES ('from-newer-backup')");

        // Restore the older one explicitly.
        $restoredFrom = $backup->restore($oldStamp);
        $this->assertSame($oldBackup, $restoredFrom);

        $pdo = new \PDO('sqlite:' . $dbPath);
        $stmt = $pdo->query('SELECT v FROM t ORDER BY id');
        $rows = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_COLUMN) : [];
        $this->assertContains('from-old-backup', $rows);
        $this->assertNotContains('from-newer-backup', $rows);
    }

    public function testRestorePropagatesWalAndShmSiblings(): void
    {
        $dbPath = $this->makeSqliteDb();
        $backup = new NavidromeDbBackup($dbPath, retention: 5);

        // Plant fake siblings on the live DB so backup() snapshots them.
        file_put_contents($dbPath . '-wal', 'wal-snapshot');
        file_put_contents($dbPath . '-shm', 'shm-snapshot');

        $backupPath = $backup->backup();
        $this->assertNotNull($backupPath);
        // The backup must carry the -wal payload byte-for-byte.
        $this->assertSame('wal-snapshot', file_get_contents($backupPath . '-wal'));

        // Wipe the live siblings, restore must recreate them from the backup.
        @unlink($dbPath . '-wal');
        @unlink($dbPath . '-shm');

        $backup->restore();

        // SQLite (called via the post-restore quick_check) may grow / rewrite
        // the -shm file to its real internal layout, even in ro mode. We only
        // assert sibling presence — content fidelity is checked on the backup
        // file above, where SQLite hasn't touched anything.
        $this->assertFileExists($dbPath . '-wal');
        $this->assertFileExists($dbPath . '-shm');
        $this->assertSame('wal-snapshot', file_get_contents($dbPath . '-wal'));
    }

    public function testRestoreWipesLiveWalWhenBackupHasNone(): void
    {
        $dbPath = $this->makeSqliteDb();
        $backup = new NavidromeDbBackup($dbPath, retention: 5);

        // Backup taken without any siblings.
        $backupPath = $backup->backup();
        $this->assertNotNull($backupPath);
        $this->assertFileDoesNotExist($backupPath . '-wal');

        // Plant stale siblings on the live DB AFTER the backup. SQLite
        // would try to replay these on top of the restored main DB → bad.
        file_put_contents($dbPath . '-wal', 'stale-wal');
        file_put_contents($dbPath . '-shm', 'stale-shm');

        $backup->restore();

        $this->assertFileDoesNotExist($dbPath . '-wal', 'stale -wal must be wiped');
        $this->assertFileDoesNotExist($dbPath . '-shm', 'stale -shm must be wiped');
    }

    public function testRestoreReturnsSourceBackupPath(): void
    {
        $dbPath = $this->makeSqliteDb();
        $backup = new NavidromeDbBackup($dbPath, retention: 5);
        $backupPath = $backup->backup();
        $this->assertNotNull($backupPath);

        $this->assertSame($backupPath, $backup->restore());
    }

    public function testRestoreThrowsWhenNoBackupExists(): void
    {
        $dbPath = $this->makeSqliteDb();
        $backup = new NavidromeDbBackup($dbPath, retention: 5);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Aucun backup disponible/');
        $backup->restore();
    }

    public function testRestoreThrowsWhenSpecificTimestampNotFound(): void
    {
        $dbPath = $this->makeSqliteDb();
        $backup = new NavidromeDbBackup($dbPath, retention: 5);
        $backup->backup();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Aucun backup trouvé pour le timestamp/');
        $backup->restore('99999999999999');
    }

    public function testRestoreThrowsWhenBackupItselfIsCorruptedAndLeavesLiveDbUntouched(): void
    {
        $dbPath = $this->makeSqliteDb();
        $backup = new NavidromeDbBackup($dbPath, retention: 5);

        // Plant a corrupt backup directly (not via backup() which would copy
        // a healthy DB).
        $stamp = '20200101000001';
        file_put_contents($dbPath . '.backup-' . $stamp, str_repeat("\xFF", 4096));

        $liveContentBefore = (string) file_get_contents($dbPath);

        try {
            $backup->restore($stamp);
            $this->fail('restore() should have thrown on a corrupt backup');
        } catch (\RuntimeException) {
            // expected
        }

        // The live DB must not have been touched — restore vets the backup
        // before copying.
        $this->assertSame($liveContentBefore, file_get_contents($dbPath));
    }

    public function testLatestBackupReturnsTimestampOfNewest(): void
    {
        $dbPath = $this->makeSqliteDb();
        foreach (['20200101000001', '20200101000002', '20200101000003'] as $stamp) {
            file_put_contents($dbPath . '.backup-' . $stamp, 'x');
        }
        $backup = new NavidromeDbBackup($dbPath, retention: 5);
        $this->assertSame('20200101000003', $backup->latestBackup());
    }

    public function testLatestBackupReturnsNullWhenNoBackups(): void
    {
        $dbPath = $this->makeSqliteDb();
        $backup = new NavidromeDbBackup($dbPath, retention: 5);
        $this->assertNull($backup->latestBackup());
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

    private function makeSqliteDbAt(string $path, string $extraSql = ''): void
    {
        $pdo = new \PDO('sqlite:' . $path);
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
        $pdo->exec("INSERT INTO t (v) VALUES ('one'), ('two')");
        if ($extraSql !== '') {
            $pdo->exec($extraSql);
        }
        unset($pdo);
        // tearDown wipes by .backup-* glob next to the source DB, so this is
        // covered when $path lives next to a tracked dbPath. For standalone
        // paths, register manually.
    }
}
