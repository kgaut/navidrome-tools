<?php

namespace App\Tests\Service;

use App\Service\BackupService;
use PHPUnit\Framework\TestCase;

class BackupServiceTest extends TestCase
{
    private string $backupDir;
    private string $sourceDb;

    protected function setUp(): void
    {
        $this->backupDir = sys_get_temp_dir() . '/backup-test-' . uniqid();
        $this->sourceDb = sys_get_temp_dir() . '/source-' . uniqid() . '.sqlite';
        file_put_contents($this->sourceDb, 'SQLite format 3' . str_repeat("\0", 81));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->backupDir . '/*') ?: [] as $f) {
            unlink($f);
        }
        if (is_dir($this->backupDir)) {
            rmdir($this->backupDir);
        }
        if (file_exists($this->sourceDb)) {
            unlink($this->sourceDb);
        }
    }

    public function testBackupCreatesFile(): void
    {
        $svc = new BackupService($this->backupDir);
        $dest = $svc->backup($this->sourceDb, 'navidrome');

        $this->assertFileExists($dest);
        $this->assertStringContainsString('navidrome', basename($dest));
        $this->assertStringEndsWith('.sqlite', $dest);
    }

    public function testBackupThrowsWhenSourceMissing(): void
    {
        $svc = new BackupService($this->backupDir);

        $this->expectException(\RuntimeException::class);
        $svc->backup('/nonexistent/db.sqlite', 'test');
    }

    public function testPruneRemovesOldFiles(): void
    {
        mkdir($this->backupDir, 0755, true);
        $old = $this->backupDir . '/2020-01-01_00-00-00_old.sqlite';
        $recent = $this->backupDir . '/2099-01-01_00-00-00_recent.sqlite';
        file_put_contents($old, 'x');
        file_put_contents($recent, 'x');
        touch($old, time() - 8 * 86400);

        $svc = new BackupService($this->backupDir);
        $removed = $svc->pruneOlderThan(7);

        $this->assertSame(1, $removed);
        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($recent);
    }

    public function testListBackupsReturnsSortedList(): void
    {
        $svc = new BackupService($this->backupDir);
        $svc->backup($this->sourceDb, 'first');
        $svc->backup($this->sourceDb, 'second');

        $list = $svc->listBackups();
        $this->assertCount(2, $list);
        $this->assertArrayHasKey('label', $list[0]);
        $this->assertArrayHasKey('size', $list[0]);
    }
}
