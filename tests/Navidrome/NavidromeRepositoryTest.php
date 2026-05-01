<?php

namespace App\Tests\Navidrome;

use App\Navidrome\NavidromeRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NavidromeRepositoryTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/navidrome-test-' . uniqid() . '.db';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testTopWithScrobblesAggregatesByCount(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);

        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Hot Track');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-2', 'Mid Track');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-3', 'Cold Track');

        // mf-1 played 5 times in last week
        for ($i = 0; $i < 5; $i++) {
            NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', date('Y-m-d H:i:s', strtotime("-{$i} day")));
        }
        // mf-2 played 2 times
        for ($i = 0; $i < 2; $i++) {
            NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-2', date('Y-m-d H:i:s', strtotime("-{$i} day")));
        }
        // mf-3 played 100 times but a year ago
        for ($i = 0; $i < 100; $i++) {
            NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-3', date('Y-m-d H:i:s', strtotime('-400 day')));
        }

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $this->assertTrue($repo->hasScrobblesTable());

        $now = new \DateTimeImmutable();
        $weekAgo = $now->modify('-7 day');
        $top = $repo->topTracksInWindow($weekAgo, $now, 10);

        $this->assertSame(['mf-1', 'mf-2'], $top, 'Only tracks in the window must appear, ordered by play count.');
    }

    public function testFallbackToAnnotationWhenNoScrobbles(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: false);

        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Hot Track');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-2', 'Mid Track');
        NavidromeFixtureFactory::insertAnnotation($conn, 'user-1', 'mf-1', 5, date('Y-m-d H:i:s', strtotime('-1 day')));
        NavidromeFixtureFactory::insertAnnotation($conn, 'user-1', 'mf-2', 10, date('Y-m-d H:i:s', strtotime('-200 day'))); // out of window

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $this->assertFalse($repo->hasScrobblesTable());

        $top = $repo->topTracksInWindow((new \DateTimeImmutable())->modify('-30 day'), new \DateTimeImmutable(), 10);
        $this->assertSame(['mf-1'], $top);
    }

    public function testNeverPlayedRandom(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath);

        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Played');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-2', 'Unknown 1');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-3', 'Unknown 2');
        NavidromeFixtureFactory::insertAnnotation($conn, 'user-1', 'mf-1', 3, date('Y-m-d H:i:s'));

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $result = $repo->neverPlayedRandom(10);

        sort($result);
        $this->assertSame(['mf-2', 'mf-3'], $result);
    }

    public function testResolveUserIdMissingThrows(): void
    {
        NavidromeFixtureFactory::createDatabase($this->dbPath);
        $repo = new NavidromeRepository($this->dbPath, 'unknown-user');
        $this->expectException(\RuntimeException::class);
        $repo->resolveUserId();
    }

    public function testSummarizePreservesOrder(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath);
        NavidromeFixtureFactory::insertTrack($conn, 'a', 'Alpha');
        NavidromeFixtureFactory::insertTrack($conn, 'b', 'Beta');
        NavidromeFixtureFactory::insertTrack($conn, 'c', 'Charlie');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $tracks = $repo->summarize(['c', 'a', 'b']);
        $this->assertSame(['Charlie', 'Alpha', 'Beta'], array_map(fn ($t) => $t->title, $tracks));
    }

    public function testFindArtistIdByNameUsesMediaFile(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Track', 'Daft Punk');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-2', 'Other Track', 'Daft Punk');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-3', 'Foo', 'Aphex Twin');

        $repo = new NavidromeRepository($this->dbPath, 'admin');

        $this->assertSame('artist-' . md5('Daft Punk'), $repo->findArtistIdByName('Daft Punk'));
        $this->assertSame('artist-' . md5('Daft Punk'), $repo->findArtistIdByName('  daft punk  '));
        $this->assertSame('artist-' . md5('Aphex Twin'), $repo->findArtistIdByName('Aphex Twin'));
        $this->assertNull($repo->findArtistIdByName('Unknown Artist'));
    }

    public function testFindMediaFileByArtistTitlePicksOneWhenMultipleMatch(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: false);
        // Same song shipped on two albums (very common: studio album + best-of).
        NavidromeFixtureFactory::insertTrack($conn, 'mf-best-of', 'Banlieusards', 'Kery James', album: '92.2012');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-studio', 'Banlieusards', 'Kery James', album: "À l'ombre du show business");

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $id = $repo->findMediaFileByArtistTitle('Kery James', 'Banlieusards');

        // Both rows have album_artist = artist (fixture default) → tie-break on id ASC.
        // The point: we no longer return null just because the song exists twice.
        $this->assertNotNull($id);
        $this->assertContains($id, ['mf-best-of', 'mf-studio']);
    }

    public function testFindMediaFileByArtistTitlePrefersAlbumArtistMatch(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: false);
        // The compilation has album_artist = "Various Artists" → less canonical.
        NavidromeFixtureFactory::insertTrack($conn, 'mf-compilation', 'Banlieusards', 'Kery James', album: 'Rap Compilation', albumArtist: 'Various Artists');
        // The studio album has album_artist = artist → canonical, should win.
        NavidromeFixtureFactory::insertTrack($conn, 'mf-studio', 'Banlieusards', 'Kery James', album: "À l'ombre du show business", albumArtist: 'Kery James');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $this->assertSame('mf-studio', $repo->findMediaFileByArtistTitle('Kery James', 'Banlieusards'));
    }

    public function testFindMediaFileByArtistTitleReturnsNullOnNoMatch(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: false);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Existing', 'Existing Artist');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $this->assertNull($repo->findMediaFileByArtistTitle('Unknown', 'Track'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function featuringVariants(): iterable
    {
        yield 'feat. dotted'      => ['Orelsan feat. Thomas Bangalter'];
        yield 'feat no dot'       => ['Orelsan feat Thomas Bangalter'];
        yield 'Feat capital'      => ['Orelsan Feat. Thomas Bangalter'];
        yield 'ft. dotted'        => ['Orelsan ft. Thomas Bangalter'];
        yield 'ft no dot'         => ['Orelsan ft Thomas Bangalter'];
        yield 'featuring spelled' => ['Orelsan featuring Thomas Bangalter'];
        yield 'parens feat'       => ['Orelsan (feat. Thomas Bangalter)'];
        yield 'parens ft'         => ['Orelsan (ft. Thomas Bangalter)'];
    }

    #[DataProvider('featuringVariants')]
    public function testFindMediaFileByArtistTitleStripsFeaturingFallback(string $lastFmArtist): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: false);
        // Navidrome stores only the lead artist on the track.
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'La pluie', 'Orelsan');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $this->assertSame(
            'mf-1',
            $repo->findMediaFileByArtistTitle($lastFmArtist, 'La pluie'),
            sprintf('"%s" should fall back to "Orelsan"', $lastFmArtist),
        );
    }

    public function testFindMediaFileByArtistTitleStrictMatchPreferredOverFallback(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: false);
        // Both rows exist; the strict match must win even though the fallback
        // would also match the lead-artist row.
        NavidromeFixtureFactory::insertTrack($conn, 'mf-strict', 'La pluie', 'Orelsan feat. Stromae');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-lead', 'La pluie', 'Orelsan');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $this->assertSame('mf-strict', $repo->findMediaFileByArtistTitle('Orelsan feat. Stromae', 'La pluie'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function versionMarkerVariants(): iterable
    {
        yield 'dash + Radio Edit'         => ['Soleil Bleu - Radio Edit'];
        yield 'parens + Radio Edit'       => ['Soleil Bleu (Radio Edit)'];
        yield 'dash + Album Version'      => ['Soleil Bleu - Album Version'];
        yield 'parens + Album Version'    => ['Soleil Bleu (Album Version)'];
        yield 'dash + Remastered'         => ['Soleil Bleu - Remastered'];
        yield 'dash + Remastered year'    => ['Soleil Bleu - Remastered 2011'];
        yield 'dash + year Remaster'      => ['Soleil Bleu - 2011 Remaster'];
        yield 'parens + Remastered year'  => ['Soleil Bleu (Remastered 2011)'];
        yield 'dash + Extended Mix'       => ['Soleil Bleu - Extended Mix'];
        yield 'dash + Mono Version'       => ['Soleil Bleu - Mono Version'];
        yield 'en dash + Radio Edit'      => ["Soleil Bleu \u{2013} Radio Edit"];
        yield 'mixed case'                => ['Soleil Bleu - Radio EDIT'];
    }

    #[DataProvider('versionMarkerVariants')]
    public function testFindMediaFileByArtistTitleStripsVersionMarker(string $lastFmTitle): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: false);
        // Navidrome stores the bare title.
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Soleil Bleu', 'Some Artist');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $this->assertSame(
            'mf-1',
            $repo->findMediaFileByArtistTitle('Some Artist', $lastFmTitle),
            sprintf('"%s" should fall back to "Soleil Bleu"', $lastFmTitle),
        );
    }

    public function testFindMediaFileByArtistTitleStrictTitlePreferredOverVersionStrip(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: false);
        // Navidrome HAS the suffixed title — strict-match wins, no strip.
        NavidromeFixtureFactory::insertTrack($conn, 'mf-edit', 'Soleil Bleu - Radio Edit', 'Some Artist');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-bare', 'Soleil Bleu', 'Some Artist');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $this->assertSame('mf-edit', $repo->findMediaFileByArtistTitle('Some Artist', 'Soleil Bleu - Radio Edit'));
    }

    public function testFindMediaFileByArtistTitleVersionStripDoesNotEatLiveOrRemix(): void
    {
        // Live / Remix / Acoustic refer to different recordings; we deliberately
        // do NOT strip them. If Navidrome doesn't have the exact suffixed row,
        // the import should leave it unmatched (better than matching the wrong
        // recording).
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: false);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-bare', 'Soleil Bleu', 'Some Artist');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $this->assertNull($repo->findMediaFileByArtistTitle('Some Artist', 'Soleil Bleu - Live'));
        $this->assertNull($repo->findMediaFileByArtistTitle('Some Artist', 'Soleil Bleu (Acoustic)'));
        $this->assertNull($repo->findMediaFileByArtistTitle('Some Artist', 'Soleil Bleu - DJ Foo Remix'));
    }

    public function testFindMediaFileByArtistTitleCombinesFeaturingAndVersionStrip(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: false);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'La pluie', 'Orelsan');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        // Both fallbacks must apply: featuring strip + version-marker strip.
        $this->assertSame('mf-1', $repo->findMediaFileByArtistTitle('Orelsan feat. Stromae', 'La pluie - Radio Edit'));
    }
}
