<?php

namespace App\Tests\Service;

use App\Navidrome\NavidromeRepository;
use App\Service\UnmatchedDiagnoser;
use App\Tests\Navidrome\NavidromeFixtureFactory;
use PHPUnit\Framework\TestCase;

/**
 * Covers the heuristic classification of unmatched scrobbles. The
 * underlying SQL probes live on {@see NavidromeRepository}; this test
 * pins the routing logic + the suggestion shapes.
 */
class UnmatchedDiagnoserTest extends TestCase
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

    public function testArtistUnknownWhenLibraryHasNothingComparable(): void
    {
        $repo = $this->seedLibrary([
            ['id' => 'mf-1', 'title' => 'Get Lucky', 'artist' => 'Daft Punk'],
        ]);

        $result = (new UnmatchedDiagnoser($repo))->diagnose('Zzz Unknown Band', 'Whatever');

        $this->assertSame(UnmatchedDiagnoser::REASON_ARTIST_UNKNOWN, $result['reason']);
        $this->assertArrayNotHasKey('artist_suggestions', $result);
    }

    public function testArtistNearMatchSurfacesSuggestion(): void
    {
        // Library has "Daft Punk". Scrobble says "Daft Pumk" (typo).
        $repo = $this->seedLibrary([
            ['id' => 'mf-1', 'title' => 'Get Lucky', 'artist' => 'Daft Punk'],
        ]);

        $result = (new UnmatchedDiagnoser($repo))->diagnose('Daft Pumk', 'Get Lucky');

        $this->assertSame(UnmatchedDiagnoser::REASON_ARTIST_NEAR_MATCH, $result['reason']);
        $this->assertNotEmpty($result['artist_suggestions']);
        $this->assertSame('Daft Punk', $result['artist_suggestions'][0]['name']);
        $this->assertSame(1, $result['artist_suggestions'][0]['distance']);
    }

    public function testTitleNearMatchWhenArtistOwnedButTitleDiffers(): void
    {
        $repo = $this->seedLibrary([
            ['id' => 'mf-1', 'title' => 'Around the World', 'artist' => 'Daft Punk'],
        ]);

        $result = (new UnmatchedDiagnoser($repo))->diagnose('Daft Punk', 'Around the Word');

        $this->assertSame(UnmatchedDiagnoser::REASON_TITLE_NEAR_MATCH, $result['reason']);
        $this->assertNotEmpty($result['title_suggestions']);
        $this->assertSame('Around the World', $result['title_suggestions'][0]['title']);
    }

    public function testMatcherGapWhenExactTrackPresent(): void
    {
        // Exact (artist, title) couple owned in the library — but the
        // unmatched row is still here (e.g. stale negative cache). The
        // diagnoser must surface this as the most actionable category.
        $repo = $this->seedLibrary([
            ['id' => 'mf-1', 'title' => 'Monument', 'artist' => 'Soleil Noir'],
        ]);

        $result = (new UnmatchedDiagnoser($repo))->diagnose('Soleil Noir', 'Monument');

        $this->assertSame(UnmatchedDiagnoser::REASON_MATCHER_GAP, $result['reason']);
        $this->assertSame('mf-1', $result['target_media_file_id']);
    }

    public function testMatcherGapAcceptsAlbumArtistColumnAsTheArtistMatch(): void
    {
        // artist="X feat. Y" + album_artist="X" — the matching cascade
        // misses it (couple step keys on `artist` only) but we own the
        // track. Must classify as MATCHER_GAP, not TRACK_MISSING.
        $repo = $this->seedLibrary([
            ['id' => 'mf-2', 'title' => 'Ensemble', 'artist' => 'OrelSan feat. Skread', 'album_artist' => 'Orelsan'],
        ]);

        $result = (new UnmatchedDiagnoser($repo))->diagnose('Orelsan', 'Ensemble');

        $this->assertSame(UnmatchedDiagnoser::REASON_MATCHER_GAP, $result['reason']);
        $this->assertSame('mf-2', $result['target_media_file_id']);
    }

    public function testTrackMissingWhenArtistOwnedButNoNearTitle(): void
    {
        $repo = $this->seedLibrary([
            ['id' => 'mf-1', 'title' => 'Around the World', 'artist' => 'Daft Punk'],
        ]);

        $result = (new UnmatchedDiagnoser($repo))->diagnose('Daft Punk', 'Something Completely Different');

        $this->assertSame(UnmatchedDiagnoser::REASON_TRACK_MISSING, $result['reason']);
    }

    public function testEmptyInputsReturnUnknown(): void
    {
        $repo = $this->seedLibrary([]);
        $diag = new UnmatchedDiagnoser($repo);

        $this->assertSame(UnmatchedDiagnoser::REASON_UNKNOWN, $diag->diagnose('', 'Title')['reason']);
        $this->assertSame(UnmatchedDiagnoser::REASON_UNKNOWN, $diag->diagnose('Artist', '   ')['reason']);
    }

    public function testArtistMatchedViaAlbumArtistColumnSkipsArtistUnknown(): void
    {
        // The library has a feat. variant on `artist` but the canonical name
        // sits in `album_artist`. The diagnoser must reuse that column too.
        $repo = $this->seedLibrary([
            ['id' => 'mf-1', 'title' => 'Ensemble', 'artist' => 'OrelSan feat. Skread', 'album_artist' => 'Orelsan'],
        ]);

        $result = (new UnmatchedDiagnoser($repo))->diagnose('Orelsan', 'Nowhere Track');

        $this->assertSame(UnmatchedDiagnoser::REASON_TRACK_MISSING, $result['reason']);
    }

    /**
     * @param list<array{id: string, title: string, artist: string, album_artist?: string}> $tracks
     */
    private function seedLibrary(array $tracks): NavidromeRepository
    {
        $path = sys_get_temp_dir() . '/nd-diag-' . uniqid() . '.db';
        $this->cleanup[] = $path;
        $conn = NavidromeFixtureFactory::createDatabase($path, withScrobbles: false);
        foreach ($tracks as $t) {
            NavidromeFixtureFactory::insertTrack(
                $conn,
                $t['id'],
                $t['title'],
                $t['artist'],
                albumArtist: $t['album_artist'] ?? null,
            );
        }
        $conn->close();

        return new NavidromeRepository($path, 'admin');
    }
}
