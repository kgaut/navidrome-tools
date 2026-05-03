<?php

namespace App\Tests\Service;

use App\Service\BackupService;
use PHPUnit\Framework\TestCase;

class BackupServiceTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/backup-service-' . uniqid();
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        // Recursively wipe the temp dir.
        foreach (glob($this->tmpDir . '/*') ?: [] as $entry) {
            if (is_dir($entry)) {
                foreach (glob($entry . '/*') ?: [] as $f) {
                    @unlink($f);
                }
                @rmdir($entry);
            } else {
                @unlink($entry);
            }
        }
        @rmdir($this->tmpDir);
    }

    public function testBackupProducesValidGzippedSqliteFile(): void
    {
        $sourcePath = $this->tmpDir . '/source.db';
        $this->createSampleDb($sourcePath);

        $backupDir = $this->tmpDir . '/backups';
        $service = new BackupService();
        $result = $service->backupSqlite($sourcePath, $backupDir, 'data');

        $this->assertFileExists($result['path']);
        $this->assertGreaterThan(0, $result['size']);
        $this->assertSame((int) filesize($result['path']), $result['size']);
        $this->assertMatchesRegularExpression(
            '~/data-\d{4}-\d{2}-\d{2}-\d{6}\.db\.gz$~',
            $result['path'],
        );

        // Decompress and verify the resulting file is a real SQLite DB
        // with the rows we inserted upstream.
        $decompressed = $this->tmpDir . '/decompressed.db';
        $this->gunzip($result['path'], $decompressed);

        $pdo = new \PDO('sqlite:' . $decompressed);
        $rows = $pdo->query('SELECT name FROM sample ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame(['alpha', 'beta'], $rows);

        // The temporary uncompressed snapshot must be cleaned up.
        $this->assertFileDoesNotExist(substr($result['path'], 0, -3));
    }

    public function testBackupCreatesDestinationDirIfMissing(): void
    {
        $sourcePath = $this->tmpDir . '/source.db';
        $this->createSampleDb($sourcePath);

        $backupDir = $this->tmpDir . '/nested/dir/backups';
        $this->assertDirectoryDoesNotExist($backupDir);

        $service = new BackupService();
        $service->backupSqlite($sourcePath, $backupDir, 'data');

        $this->assertDirectoryExists($backupDir);
    }

    public function testBackupRejectsMissingSource(): void
    {
        $service = new BackupService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Source SQLite database not found/');
        $service->backupSqlite($this->tmpDir . '/missing.db', $this->tmpDir, 'data');
    }

    public function testListBackupsSortsByMtimeDescAndIgnoresUnrelatedFiles(): void
    {
        $backupDir = $this->tmpDir . '/backups';
        mkdir($backupDir);

        $oldFile = $backupDir . '/data-2025-01-01-000000.db.gz';
        $newFile = $backupDir . '/data-2026-05-03-120000.db.gz';
        $other = $backupDir . '/navidrome-2026-05-03-120000.db.gz';
        $stranger = $backupDir . '/random.txt';

        file_put_contents($oldFile, "\x1f\x8b old");
        file_put_contents($newFile, "\x1f\x8b new");
        file_put_contents($other, "\x1f\x8b nav");
        file_put_contents($stranger, 'noise');
        // Touch mtimes explicitly so the test isn't time-of-day dependent.
        touch($oldFile, 1_700_000_000);
        touch($newFile, 1_750_000_000);
        touch($other, 1_750_000_000);

        $service = new BackupService();
        $entries = $service->listBackups($backupDir, 'data');

        $this->assertCount(2, $entries);
        $this->assertSame(basename($newFile), $entries[0]['name']);
        $this->assertSame(basename($oldFile), $entries[1]['name']);
    }

    public function testPruneOlderThanDeletesExpiredFilesOnly(): void
    {
        $backupDir = $this->tmpDir . '/backups';
        mkdir($backupDir);

        $old = $backupDir . '/data-2024-01-01-000000.db.gz';
        $recent = $backupDir . '/data-2026-04-30-000000.db.gz';
        $unrelated = $backupDir . '/navidrome-2024-01-01-000000.db.gz';

        file_put_contents($old, 'x');
        file_put_contents($recent, 'x');
        file_put_contents($unrelated, 'x');
        // 90 days ago = ~7_776_000s ; pin both old files to ~1 year ago.
        touch($old, time() - 365 * 86400);
        touch($unrelated, time() - 365 * 86400);
        touch($recent, time() - 3 * 86400);

        $service = new BackupService();
        $deleted = $service->pruneOlderThan($backupDir, 'data', 30);

        $this->assertSame(1, $deleted);
        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($recent);
        // pruneOlderThan must never touch files of a different prefix.
        $this->assertFileExists($unrelated);
    }

    public function testPruneOlderThanWithZeroRetentionIsNoop(): void
    {
        $backupDir = $this->tmpDir . '/backups';
        mkdir($backupDir);
        $f = $backupDir . '/data-2020-01-01-000000.db.gz';
        file_put_contents($f, 'x');
        touch($f, 1);

        $service = new BackupService();
        $this->assertSame(0, $service->pruneOlderThan($backupDir, 'data', 0));
        $this->assertFileExists($f);
    }

    private function createSampleDb(string $path): void
    {
        $pdo = new \PDO('sqlite:' . $path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE sample (id INTEGER PRIMARY KEY, name TEXT)');
        $pdo->exec("INSERT INTO sample (name) VALUES ('alpha'), ('beta')");
    }

    private function gunzip(string $source, string $destination): void
    {
        $in = gzopen($source, 'rb');
        $out = fopen($destination, 'wb');
        while (!gzeof($in)) {
            fwrite($out, gzread($in, 64 * 1024));
        }
        fclose($out);
        gzclose($in);
    }
}
