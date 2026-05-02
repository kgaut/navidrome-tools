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
        yield 'dash + Radio Edit'             => ['Soleil Bleu - Radio Edit'];
        yield 'parens + Radio Edit'           => ['Soleil Bleu (Radio Edit)'];
        yield 'brackets + Radio Edit'         => ['Soleil Bleu [Radio Edit]'];
        yield 'dash + Album Version'          => ['Soleil Bleu - Album Version'];
        yield 'parens + Album Version'        => ['Soleil Bleu (Album Version)'];
        yield 'dash + Remastered'             => ['Soleil Bleu - Remastered'];
        yield 'dash + Remastered year'        => ['Soleil Bleu - Remastered 2011'];
        yield 'dash + year Remaster'          => ['Soleil Bleu - 2011 Remaster'];
        yield 'parens + Remastered year'      => ['Soleil Bleu (Remastered 2011)'];
        yield 'dash + Extended Mix'           => ['Soleil Bleu - Extended Mix'];
        yield 'dash + Mono Version'           => ['Soleil Bleu - Mono Version'];
        yield 'en dash + Radio Edit'          => ["Soleil Bleu \u{2013} Radio Edit"];
        yield 'mixed case'                    => ['Soleil Bleu - Radio EDIT'];
        yield 'parens + Live'                 => ['Soleil Bleu (Live)'];
        yield 'parens + Live at venue'        => ['Soleil Bleu (Live at Reading 1992)'];
        yield 'dash + Live'                   => ['Soleil Bleu - Live'];
        yield 'parens + Acoustic'             => ['Soleil Bleu (Acoustic)'];
        yield 'parens + Acoustic Version'     => ['Soleil Bleu (Acoustic Version)'];
        yield 'parens + Instrumental'         => ['Soleil Bleu (Instrumental)'];
        yield 'parens + Demo'                 => ['Soleil Bleu (Demo)'];
        yield 'parens + Deluxe Edition'       => ['Soleil Bleu (Deluxe Edition)'];
        yield 'dash + Deluxe Version'         => ['Soleil Bleu - Deluxe Version'];
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

    public function testFindMediaFileByArtistTitleStripDoesNotEatTitleBodyKeywords(): void
    {
        // Decoration patterns require explicit delimiters (parens / brackets
        // / dash), so a title like "Live and Let Die" or "Live Forever" must
        // remain intact when matching against the same bare row.
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: false);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-let-die', 'Live and Let Die', 'Wings');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-forever', 'Live Forever', 'Oasis');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $this->assertSame('mf-let-die', $repo->findMediaFileByArtistTitle('Wings', 'Live and Let Die'));
        $this->assertSame('mf-forever', $repo->findMediaFileByArtistTitle('Oasis', 'Live Forever'));
    }

    public function testFindMediaFileByArtistTitleVersionStripLeavesRemixAlone(): void
    {
        // Remix is intentionally NOT in the strip list — DJ remixes are
        // distinct recordings. If the lib doesn't have the suffixed row,
        // the import should leave it unmatched.
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: false);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-bare', 'Soleil Bleu', 'Some Artist');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $this->assertNull($repo->findMediaFileByArtistTitle('Some Artist', 'Soleil Bleu - DJ Foo Remix'));
        $this->assertNull($repo->findMediaFileByArtistTitle('Some Artist', 'Soleil Bleu (Club Remix)'));
    }

    public function testFindMediaFileByArtistTitleStripsFeaturingFromTitle(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: false);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Crazy in Love', 'Beyonce');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-2', 'Bad Guy', 'Billie Eilish');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        // "(feat. X)" / "(ft. X)" / "(featuring X)" / "(with X)" suffixes
        // are stripped from the title side. Brackets work too.
        $this->assertSame('mf-1', $repo->findMediaFileByArtistTitle('Beyonce', 'Crazy in Love (feat. Jay-Z)'));
        $this->assertSame('mf-1', $repo->findMediaFileByArtistTitle('Beyonce', 'Crazy in Love (ft. Jay-Z)'));
        $this->assertSame('mf-1', $repo->findMediaFileByArtistTitle('Beyonce', 'Crazy in Love [featuring Jay-Z]'));
        $this->assertSame('mf-2', $repo->findMediaFileByArtistTitle('Billie Eilish', 'Bad Guy (with Justin Bieber)'));
    }

    public function testFindMediaFileByArtistTitleCombinesFeaturingAndVersionStrip(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: false);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'La pluie', 'Orelsan');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        // Both fallbacks must apply: featuring strip + version-marker strip.
        $this->assertSame('mf-1', $repo->findMediaFileByArtistTitle('Orelsan feat. Stromae', 'La pluie - Radio Edit'));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function accentVariants(): iterable
    {
        // [stored_artist, stored_title, query_artist, query_title]
        yield 'acute accent (Beyoncé)'      => ['Beyoncé', 'Halo', 'Beyonce', 'Halo'];
        yield 'reverse query (no accent → accented)' => ['Beyonce', 'Halo', 'Beyoncé', 'Halo'];
        yield 'multiple acutes (Sigur Rós)' => ['Sigur Rós', 'Hoppípolla', 'Sigur Ros', 'Hoppipolla'];
        yield 'umlauts (Mötörhead)'         => ['Mötörhead', 'Ace of Spades', 'Motorhead', 'Ace of Spades'];
        yield 'cedilla (Café Tacvba)'       => ['Café Tacvba', 'La Ingrata', 'Cafe Tacvba', 'La Ingrata'];
        yield 'accent in title only'        => ['Stromae', 'Papaoutaï', 'Stromae', 'Papaoutai'];
    }

    #[DataProvider('accentVariants')]
    public function testFindMediaFileByArtistTitleIgnoresDiacritics(
        string $storedArtist,
        string $storedTitle,
        string $queryArtist,
        string $queryTitle,
    ): void {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: false);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', $storedTitle, $storedArtist);

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $this->assertSame('mf-1', $repo->findMediaFileByArtistTitle($queryArtist, $queryTitle));
    }

    public function testFindArtistIdByNameIgnoresDiacritics(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Halo', 'Beyoncé');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-2', 'Hoppípolla', 'Sigur Rós');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $this->assertSame('artist-' . md5('Beyoncé'), $repo->findArtistIdByName('Beyonce'));
        $this->assertSame('artist-' . md5('Sigur Rós'), $repo->findArtistIdByName('Sigur Ros'));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function punctuationVariants(): iterable
    {
        // [stored_artist, stored_title, query_artist, query_title]
        // Note: "P!nk" ↔ "Pink" is NOT covered here — naive punctuation strip
        // turns "P!nk" into "pnk" (the "!" is not a vowel), so the two forms
        // remain non-equal. That case requires fuzzy matching (sub-issue #16).
        yield 'slash (AC/DC)'                    => ['AC/DC', 'Thunderstruck', 'ACDC', 'Thunderstruck'];
        yield 'reverse slash (ACDC ← AC/DC)'     => ['ACDC', 'Thunderstruck', 'AC/DC', 'Thunderstruck'];
        yield 'apostrophe (Guns N\' Roses)'      => ["Guns N' Roses", 'November Rain', 'Guns N Roses', 'November Rain'];
        yield 'curly apostrophe (Guns N’ Roses)' => ['Guns N’ Roses', 'November Rain', 'Guns N Roses', 'November Rain'];
        yield 'dotted (t.A.T.u.)'                => ['t.A.T.u.', 'All The Things She Said', 'tATu', 'All The Things She Said'];
        yield 'punct in title (O\' Mine)'        => ['Guns N Roses', "Sweet Child O' Mine", 'Guns N Roses', 'Sweet Child O Mine'];
    }

    #[DataProvider('punctuationVariants')]
    public function testFindMediaFileByArtistTitleStripsPunctuation(
        string $storedArtist,
        string $storedTitle,
        string $queryArtist,
        string $queryTitle,
    ): void {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: false);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', $storedTitle, $storedArtist);

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $this->assertSame('mf-1', $repo->findMediaFileByArtistTitle($queryArtist, $queryTitle));
    }

    public function testFindMediaFileByArtistTitleCollapsesWhitespace(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: false);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Some  Track', 'Some  Artist');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $this->assertSame('mf-1', $repo->findMediaFileByArtistTitle('Some Artist', 'Some Track'));
        $this->assertSame('mf-1', $repo->findMediaFileByArtistTitle('Some   Artist', 'Some   Track'));
    }

    public function testFindMediaFileByArtistTitleAlbumPicksByAlbum(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: false);
        // Same song shipped on the studio album AND on a single — album
        // disambiguates which row the user actually played.
        NavidromeFixtureFactory::insertTrack($conn, 'mf-album', 'One More Time', 'Daft Punk', album: 'Discovery');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-single', 'One More Time', 'Daft Punk', album: 'One More Time');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $this->assertSame('mf-album', $repo->findMediaFileByArtistTitleAlbum('Daft Punk', 'One More Time', 'Discovery'));
        $this->assertSame('mf-single', $repo->findMediaFileByArtistTitleAlbum('Daft Punk', 'One More Time', 'One More Time'));
    }

    public function testFindMediaFileByArtistTitleAlbumNormalizesAccentAndPunctuation(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: false);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Halo', 'Beyoncé', album: "I Am... Sasha Fierce");

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $this->assertSame('mf-1', $repo->findMediaFileByArtistTitleAlbum('Beyonce', 'Halo', 'I Am Sasha Fierce'));
    }

    public function testFindMediaFileByArtistTitleAlbumReturnsNullWhenAmbiguous(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: false);
        // Same triplet inserted twice (e.g., duplicate import) → ambiguous.
        NavidromeFixtureFactory::insertTrack($conn, 'mf-a', 'One More Time', 'Daft Punk', album: 'Discovery');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-b', 'One More Time', 'Daft Punk', album: 'Discovery');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $this->assertNull($repo->findMediaFileByArtistTitleAlbum('Daft Punk', 'One More Time', 'Discovery'));
    }

    public function testFindMediaFileByArtistTitleAlbumReturnsNullOnNoMatch(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: false);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-album', 'One More Time', 'Daft Punk', album: 'Discovery');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        // Album doesn't match → caller should fall back to the bare couple lookup.
        $this->assertNull($repo->findMediaFileByArtistTitleAlbum('Daft Punk', 'One More Time', 'Some Compilation'));
    }

    public function testFindMediaFileByArtistTitleAlbumReturnsNullOnEmptyAlbum(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: false);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'One More Time', 'Daft Punk');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $this->assertNull($repo->findMediaFileByArtistTitleAlbum('Daft Punk', 'One More Time', ''));
    }

    public function testFindMediaFileFuzzyMatchesSmallTypos(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: false);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Take Me to Church', 'Hozier');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        // 1 typo on artist + 1 typo on title = distance 2, under threshold 3.
        $this->assertSame('mf-1', $repo->findMediaFileFuzzy('Hosier', 'Take Me to Chruch', 3));
    }

    public function testFindMediaFileFuzzyReturnsNullWhenDisabled(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: false);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Take Me to Church', 'Hozier');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        // 0 → fuzzy is off, returns null even on a near-perfect typo.
        $this->assertNull($repo->findMediaFileFuzzy('Hosier', 'Take Me to Church', 0));
        $this->assertNull($repo->findMediaFileFuzzy('Hosier', 'Take Me to Church', -1));
    }

    public function testFindMediaFileFuzzyReturnsNullWhenAboveThreshold(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: false);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Take Me to Church', 'Hozier');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        // Distance way above 3 → null.
        $this->assertNull($repo->findMediaFileFuzzy('Completely Different Artist', 'Some Other Song', 3));
    }

    public function testFindMediaFileFuzzyPicksClosestMatch(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: false);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-far', 'Some Song', 'Far Artist');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-near', 'Some Sang', 'Far Artist'); // distance 1
        NavidromeFixtureFactory::insertTrack($conn, 'mf-exact', 'Some Song', 'Far Artist'); // distance 0 (exact)

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        // Should pick the lowest-distance candidate.
        $this->assertSame('mf-far', $repo->findMediaFileFuzzy('Far Artist', 'Some Song', 3));
    }

    public function testFindMediaFileFuzzyOnEmptyInputs(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: false);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Track', 'Artist');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $this->assertNull($repo->findMediaFileFuzzy('', 'Track', 3));
        $this->assertNull($repo->findMediaFileFuzzy('Artist', '', 3));
    }
}
