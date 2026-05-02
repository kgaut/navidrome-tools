<?php

namespace App\Tests\Controller;

use App\Controller\CoverArtController;
use App\Service\CoverArtCache;
use App\Subsonic\SubsonicClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;

class CoverArtControllerTest extends TestCase
{
    private string $cacheRoot;

    protected function setUp(): void
    {
        $this->cacheRoot = sys_get_temp_dir() . '/cover-ctrl-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->cacheRoot)) {
            $this->rrmdir($this->cacheRoot);
        }
    }

    public function testCacheHitServesFileWithoutSubsonicCall(): void
    {
        $cache = new CoverArtCache($this->cacheRoot);
        $cache->store('album', 'mf-1', 128, 'jpegbytes');

        $sub = $this->subsonicNeverCalled();
        $controller = new CoverArtController($cache, $sub);

        $response = $controller->show('album', 'mf-1', new Request(['size' => '128']));

        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
    }

    public function testCacheMissFetchesAndStores(): void
    {
        $cache = new CoverArtCache($this->cacheRoot);

        $sub = new class extends SubsonicClient {
            public int $calls = 0;
            public function __construct() // override — skip parent
            {
            }
            public function fetchCoverArt(string $id, int $size = 0): string
            {
                $this->calls++;

                return 'newbytes';
            }
        };

        $controller = new CoverArtController($cache, $sub);
        $response = $controller->show('artist', 'art-7', new Request(['size' => '64']));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $sub->calls);
        $this->assertSame('newbytes', file_get_contents($cache->pathFor('artist', 'art-7', 64)));
    }

    public function testSubsonicErrorBecomes404(): void
    {
        $cache = new CoverArtCache($this->cacheRoot);

        $sub = new class extends SubsonicClient {
            public function __construct()
            {
            }
            public function fetchCoverArt(string $id, int $size = 0): string
            {
                throw new \RuntimeException('subsonic boom');
            }
        };

        $controller = new CoverArtController($cache, $sub);
        $response = $controller->show('album', 'unknown', new Request(['size' => '128']));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testSizeIsClampedAt1024(): void
    {
        $cache = new CoverArtCache($this->cacheRoot);

        $sub = new class extends SubsonicClient {
            public int $observedSize = -1;
            public function __construct()
            {
            }
            public function fetchCoverArt(string $id, int $size = 0): string
            {
                $this->observedSize = $size;

                return 'x';
            }
        };

        $controller = new CoverArtController($cache, $sub);
        $controller->show('album', 'a', new Request(['size' => '99999']));

        $this->assertSame(1024, $sub->observedSize);
    }

    public function testNonNumericSizeFallsBackToDefault(): void
    {
        $cache = new CoverArtCache($this->cacheRoot);

        $sub = new class extends SubsonicClient {
            public int $observedSize = -1;
            public function __construct()
            {
            }
            public function fetchCoverArt(string $id, int $size = 0): string
            {
                $this->observedSize = $size;

                return 'x';
            }
        };

        $controller = new CoverArtController($cache, $sub);
        $controller->show('album', 'a', new Request(['size' => '0']));

        // 0 falls below MIN_SIZE → bumped back to default 128.
        $this->assertSame(128, $sub->observedSize);
    }

    private function subsonicNeverCalled(): SubsonicClient
    {
        return new class extends SubsonicClient {
            public function __construct()
            {
            }
            public function fetchCoverArt(string $id, int $size = 0): string
            {
                throw new \RuntimeException('Should not be reached on cache hit.');
            }
        };
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
