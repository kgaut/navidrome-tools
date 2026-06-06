<?php

namespace App\Tests\Twig;

use App\Twig\LidarrExtension;
use PHPUnit\Framework\TestCase;

class LidarrExtensionTest extends TestCase
{
    public function testReturnsNullWhenLidarrUrlIsEmpty(): void
    {
        $ext = new LidarrExtension('');

        $this->assertNull($ext->artistUrl('Beyoncé'));
        $this->assertNull($ext->albumUrl('Beyoncé', 'Lemonade'));
    }

    public function testReturnsNullWhenArtistIsEmpty(): void
    {
        $ext = new LidarrExtension('https://lidarr.local');

        $this->assertNull($ext->artistUrl('   '));
        $this->assertNull($ext->albumUrl('', 'Some album'));
    }

    public function testArtistUrlEncodesAccentsAndSpaces(): void
    {
        $ext = new LidarrExtension('https://lidarr.local');

        $this->assertSame(
            'https://lidarr.local/add/new?term=Sigur%20R%C3%B3s',
            $ext->artistUrl('Sigur Rós'),
        );
    }

    public function testTrailingSlashOnLidarrUrlIsStripped(): void
    {
        $ext = new LidarrExtension('https://lidarr.local/');

        $this->assertSame(
            'https://lidarr.local/add/new?term=Foo',
            $ext->artistUrl('Foo'),
        );
    }

    public function testAlbumUrlCombinesArtistAndAlbumInOneSearchTerm(): void
    {
        $ext = new LidarrExtension('https://lidarr.local');

        $this->assertSame(
            'https://lidarr.local/add/new?term=Daft%20Punk%20Discovery',
            $ext->albumUrl('Daft Punk', 'Discovery'),
        );
    }

    public function testAlbumUrlFallsBackToArtistOnlyWhenAlbumEmpty(): void
    {
        $ext = new LidarrExtension('https://lidarr.local');

        $this->assertSame(
            'https://lidarr.local/add/new?term=Daft%20Punk',
            $ext->albumUrl('Daft Punk', ''),
        );
    }

    public function testRegistersTwoFunctions(): void
    {
        $ext = new LidarrExtension('https://lidarr.local');
        $names = array_map(static fn ($f) => $f->getName(), $ext->getFunctions());

        $this->assertSame(['lidarr_artist_url', 'lidarr_album_url'], $names);
    }

    public function testNullLidarrUrlIsToleratedFromEnvDefault(): void
    {
        // Symfony's default:: env processor resolves to null when LIDARR_URL
        // isn't set at all — the ctor must accept that without crashing.
        $ext = new LidarrExtension(null);

        $this->assertNull($ext->artistUrl('Anyone'));
    }
}
