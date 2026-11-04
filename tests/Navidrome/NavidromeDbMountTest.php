<?php

namespace App\Tests\Navidrome;

use App\Navidrome\NavidromeRepository;
use PHPUnit\Framework\TestCase;

/**
 * Issue #298: navidrome.db bind-mounted as a single file gives our process
 * its own -wal/-shm, invisible to Navidrome — writes are refused.
 */
class NavidromeDbMountTest extends TestCase
{
    private string $dir;
    private string $db;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nd-mount-' . uniqid();
        mkdir($this->dir);
        $this->db = $this->dir . '/navidrome.db';
        NavidromeFixtureFactory::createDatabase($this->db, withScrobbles: true)->close();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->dir);
    }

    public function testSingleFileMountIsDetectedAndWritesRefused(): void
    {
        $repo = new NavidromeRepository($this->db, 'admin', $this->mountInfo([
            '22 1 8:1 / / rw,relatime - ext4 /dev/sda1 rw',
            '650 600 8:1 /home/u/docker/zik/data/navidrome/navidrome.db ' . $this->db . ' rw,relatime - ext4 /dev/sda1 rw',
        ]));

        $this->assertTrue($repo->isSingleFileMount());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('monté comme fichier seul');
        $repo->beginWriteTransaction();
    }

    public function testDirectoryMountAllowsWrites(): void
    {
        $repo = new NavidromeRepository($this->db, 'admin', $this->mountInfo([
            '22 1 8:1 / / rw,relatime - ext4 /dev/sda1 rw',
            '650 600 8:1 /home/u/docker/zik/data/navidrome ' . $this->dir . ' rw,relatime - ext4 /dev/sda1 rw',
        ]));

        $this->assertFalse($repo->isSingleFileMount());
        $repo->beginWriteTransaction();
        $repo->rollbackWrite();
        $repo->closeWriteConnection();
    }

    public function testUnreadableMountInfoDoesNotBlock(): void
    {
        $this->assertFalse((new NavidromeRepository($this->db, 'admin', $this->dir . '/missing'))->isSingleFileMount());
    }

    /**
     * @param list<string> $lines
     */
    private function mountInfo(array $lines): string
    {
        $path = $this->dir . '/mountinfo';
        file_put_contents($path, implode("\n", $lines) . "\n");

        return $path;
    }
}
