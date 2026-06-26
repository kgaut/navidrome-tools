<?php

namespace App\Tests\Navidrome;

use App\Navidrome\NavidromeRepository;
use PHPUnit\Framework\TestCase;

/**
 * Direct coverage of the couple cascade
 * ({@see NavidromeRepository::findMediaFileByArtistTitle()}) against a
 * real SQLite media_file table. Focused on the album_artist widening
 * (PR B) and on guarding the false-positive boundaries.
 */
class NavidromeCoupleLookupTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            foreach ([$path, $path . '-wal', $path . '-shm'] as $f) {
                if (file_exists($f)) {
                    unlink($f);
                }
            }
        }
    }

    public function testExactArtistTitleMatches(): void
    {
        $repo = $this->seed([
            ['id' => 'mf-1', 'title' => 'Get Lucky', 'artist' => 'Daft Punk'],
        ]);

        $this->assertSame('mf-1', $repo->findMediaFileByArtistTitle('Daft Punk', 'Get Lucky'));
    }

    public function testCollabResolvedViaAlbumArtistColumn(): void
    {
        // Last.fm scrobbles "Orelsan"; the track is credited to
        // "Orelsan feat. Skread" but the album_artist is "Orelsan".
        // The artist-only step misses it — the album_artist widening
        // catches it.
        $repo = $this->seed([
            ['id' => 'mf-1', 'title' => 'Ensemble', 'artist' => 'OrelSan feat. Skread', 'album_artist' => 'Orelsan'],
        ]);

        $this->assertSame('mf-1', $repo->findMediaFileByArtistTitle('Orelsan', 'Ensemble'));
    }

    public function testPrimaryArtistColumnPreferredOverAlbumArtist(): void
    {
        // Two tracks share the title; one matches on artist, the other
        // only on album_artist. The artist-column hit must win.
        $repo = $this->seed([
            ['id' => 'mf-album', 'title' => 'Intro', 'artist' => 'Someone Else', 'album_artist' => 'Dr Dre'],
            ['id' => 'mf-artist', 'title' => 'Intro', 'artist' => 'Dr Dre', 'album_artist' => 'Dr Dre'],
        ]);

        $this->assertSame('mf-artist', $repo->findMediaFileByArtistTitle('Dr Dre', 'Intro'));
    }

    public function testNoMatchWhenNeitherColumnMatches(): void
    {
        $repo = $this->seed([
            ['id' => 'mf-1', 'title' => 'Get Lucky', 'artist' => 'Daft Punk', 'album_artist' => 'Daft Punk'],
        ]);

        $this->assertNull($repo->findMediaFileByArtistTitle('Justice', 'Get Lucky'));
    }

    public function testTitleStaysStrictUnderAlbumArtistWidening(): void
    {
        // album_artist matches but the title doesn't — must NOT match,
        // proving the widening is on the artist axis only.
        $repo = $this->seed([
            ['id' => 'mf-1', 'title' => 'Civilisation', 'artist' => 'Orelsan', 'album_artist' => 'Orelsan'],
        ]);

        $this->assertNull($repo->findMediaFileByArtistTitle('Orelsan', 'Totally Other Title'));
    }

    public function testTripletExactAlbumMatches(): void
    {
        $repo = $this->seed([
            ['id' => 'mf-1', 'title' => 'Get Lucky', 'artist' => 'Daft Punk', 'album' => 'Random Access Memories'],
        ]);

        $this->assertSame(
            'mf-1',
            $repo->findMediaFileByArtistTitleAlbum('Daft Punk', 'Get Lucky', 'Random Access Memories'),
        );
    }

    public function testTripletFallsBackOnStrippedAlbumDecoration(): void
    {
        // Library album carries "(Deluxe Edition)"; Last.fm scrobble has
        // the plain name. Exact triplet misses, stripped retry catches it.
        $repo = $this->seed([
            ['id' => 'mf-1', 'title' => 'Get Lucky', 'artist' => 'Daft Punk', 'album' => 'Random Access Memories (Deluxe Edition)'],
        ]);

        $this->assertSame(
            'mf-1',
            $repo->findMediaFileByArtistTitleAlbum('Daft Punk', 'Get Lucky', 'Random Access Memories'),
        );
    }

    public function testTripletStrippedFallbackIsSymmetric(): void
    {
        // Reverse: library plain, scrobble decorated.
        $repo = $this->seed([
            ['id' => 'mf-1', 'title' => 'Get Lucky', 'artist' => 'Daft Punk', 'album' => 'Random Access Memories'],
        ]);

        $this->assertSame(
            'mf-1',
            $repo->findMediaFileByArtistTitleAlbum('Daft Punk', 'Get Lucky', 'Random Access Memories [Remastered]'),
        );
    }

    public function testTripletStrippedFallbackBailsWhenAmbiguous(): void
    {
        // Two different albums collapse to the same stripped form — don't
        // guess which pressing the user played.
        $repo = $this->seed([
            ['id' => 'mf-a', 'title' => 'Get Lucky', 'artist' => 'Daft Punk', 'album' => 'Random Access Memories (Deluxe Edition)'],
            ['id' => 'mf-b', 'title' => 'Get Lucky', 'artist' => 'Daft Punk', 'album' => 'Random Access Memories [Remastered]'],
        ]);

        $this->assertNull(
            $repo->findMediaFileByArtistTitleAlbum('Daft Punk', 'Get Lucky', 'Random Access Memories'),
        );
    }

    public function testTripletNoMatchWhenAlbumGenuinelyDiffers(): void
    {
        $repo = $this->seed([
            ['id' => 'mf-1', 'title' => 'Get Lucky', 'artist' => 'Daft Punk', 'album' => 'Random Access Memories'],
        ]);

        $this->assertNull(
            $repo->findMediaFileByArtistTitleAlbum('Daft Punk', 'Get Lucky', 'Discovery'),
        );
    }

    public function testInverseFeaturingCleanScrobbleMatchesFeatVariant(): void
    {
        // Last.fm scrobbles a clean couple; the library credits a feat.
        // variant in the artist column. No featuring marker on either
        // Last.fm field — the inverse asymmetric step must catch it.
        $repo = $this->seed([
            ['id' => 'mf-1', 'title' => 'Crazy in Love', 'artist' => 'Beyoncé feat. Jay-Z'],
        ]);

        $this->assertSame('mf-1', $repo->findMediaFileByArtistTitle('Beyoncé', 'Crazy in Love'));
    }

    public function testInverseFeaturingDoesNotMatchDifferentTitle(): void
    {
        // Title stays strict — a feat. artist prefix is not enough on its own.
        $repo = $this->seed([
            ['id' => 'mf-1', 'title' => 'Crazy in Love', 'artist' => 'Beyoncé feat. Jay-Z'],
        ]);

        $this->assertNull($repo->findMediaFileByArtistTitle('Beyoncé', 'Halo'));
    }

    public function testLeadArtistSplitOnXSeparatorRequiresAlbumArtist(): void
    {
        // "Diplo x M.I.A." scrobble; library track credited to "Diplo"
        // with album_artist "Diplo" → the conservative lead-split path
        // (requires album_artist) resolves it.
        $repo = $this->seed([
            ['id' => 'mf-1', 'title' => 'Paper Planes', 'artist' => 'Diplo', 'album_artist' => 'Diplo'],
        ]);

        $this->assertSame('mf-1', $repo->findMediaFileByArtistTitle('Diplo x M.I.A.', 'Paper Planes'));
    }

    /**
     * @param list<array{id: string, title: string, artist: string, album_artist?: string, album?: string}> $tracks
     */
    private function seed(array $tracks): NavidromeRepository
    {
        $path = sys_get_temp_dir() . '/nd-couple-' . uniqid() . '.db';
        $this->cleanup[] = $path;
        $conn = NavidromeFixtureFactory::createDatabase($path, withScrobbles: false);
        foreach ($tracks as $t) {
            NavidromeFixtureFactory::insertTrack(
                $conn,
                $t['id'],
                $t['title'],
                $t['artist'],
                album: $t['album'] ?? 'Album',
                albumArtist: $t['album_artist'] ?? null,
            );
        }
        $conn->close();

        return new NavidromeRepository($path, 'admin');
    }
}
