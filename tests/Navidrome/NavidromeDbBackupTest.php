<?php

namespace App\Tests\Navidrome;

use App\Navidrome\NavidromeDbBackup;
use PHPUnit\Framework\TestCase;

/**
 * Issue #297: an unsound copy must never push sound backups out of the
 * retention window.
 */
class NavidromeDbBackupTest extends TestCase
{
    private string $dir;
    private string $db;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nd-backup-' . uniqid();
        mkdir($this->dir);
        $this->db = $this->dir . '/navidrome.db';
        $pdo = new \PDO('sqlite:' . $this->db);
        $pdo->exec('PRAGMA page_size = 1024; CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT); CREATE INDEX iv ON t (v)');
        $pdo->beginTransaction();
        for ($i = 0; $i < 3000; $i++) {
            $pdo->exec("INSERT INTO t (v) VALUES ('" . str_repeat('x', 40) . $i . "')");
        }
        $pdo->commit();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    public function testSoundCopyIsKeptAndRetentionApplies(): void
    {
        $backup = new NavidromeDbBackup($this->db, 2);
        foreach (['20260101000000', '20260102000000'] as $ts) {
            copy($this->db, $this->db . '.backup-' . $ts);
        }

        $path = $backup->backup();

        $this->assertNotNull($path);
        $this->assertFileExists($path);
        $this->assertCount(2, $backup->listBackups());
        $this->assertFileDoesNotExist($this->db . '.backup-20260101000000');
    }

    public function testUnsoundCopyIsDroppedAndPrunesNothing(): void
    {
        $backup = new NavidromeDbBackup($this->db, 2);
        foreach (['20260101000000', '20260102000000'] as $ts) {
            copy($this->db, $this->db . '.backup-' . $ts);
        }
        // Corrupt the live DB (index/table pages).
        $h = fopen($this->db, 'r+');
        fseek($h, 1024 * 30 + 64);
        fwrite($h, random_bytes(700));
        fclose($h);

        try {
            $backup->backup();
            $this->fail('An unsound copy must throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('non saine', $e->getMessage());
        }

        $this->assertSame(
            [$this->db . '.backup-20260101000000', $this->db . '.backup-20260102000000'],
            $backup->listBackups(),
        );
    }
}
