<?php

namespace App\Tests\Service;

use App\Service\CoverArtCache;
use PHPUnit\Framework\TestCase;

class CoverArtCacheTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/covers-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $this->rrmdir($this->root);
        }
    }

    public function testPathForBuildsExpectedLayout(): void
    {
        $cache = new CoverArtCache($this->root);
        $this->assertSame($this->root . '/album/abc-123-def-128.jpg', $cache->pathFor('album', 'abc-123-def', 128));
        $this->assertSame($this->root . '/artist/foo_bar-256.jpg', $cache->pathFor('artist', 'foo_bar', 256));
    }

    public function testPathForRejectsUnknownType(): void
    {
        $cache = new CoverArtCache($this->root);
        $this->expectException(\InvalidArgumentException::class);
        $cache->pathFor('playlist', 'abc', 128);
    }

    public function testPathForRejectsTraversalAttempts(): void
    {
        $cache = new CoverArtCache($this->root);
        foreach (['../../etc', 'a/b', 'foo bar', '', 'foo.jpg'] as $bad) {
            try {
                $cache->pathFor('album', $bad, 128);
                $this->fail('Expected InvalidArgumentException for id "' . $bad . '"');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testPathForRejectsNegativeSize(): void
    {
        $cache = new CoverArtCache($this->root);
        $this->expectException(\InvalidArgumentException::class);
        $cache->pathFor('album', 'abc', -1);
    }

    public function testGetReturnsNullOnMiss(): void
    {
        $cache = new CoverArtCache($this->root);
        $this->assertNull($cache->get('album', 'never-seen', 128));
    }

    public function testStoreThenGetRoundTrip(): void
    {
        $cache = new CoverArtCache($this->root);
        $bytes = random_bytes(64);
        $stored = $cache->store('album', 'abc-123', 128, $bytes);

        $this->assertFileExists($stored);
        $this->assertSame($bytes, file_get_contents($stored));
        $this->assertSame($stored, $cache->get('album', 'abc-123', 128));
    }

    public function testStoreCreatesParentDirectory(): void
    {
        $cache = new CoverArtCache($this->root);
        $this->assertDirectoryDoesNotExist($this->root);
        $cache->store('artist', 'art-1', 256, 'x');
        $this->assertDirectoryExists($this->root . '/artist');
    }

    private function rrmdir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir . '/' . $f;
            is_dir($p) ? $this->rrmdir($p) : unlink($p);
        }
        rmdir($dir);
    }
}
