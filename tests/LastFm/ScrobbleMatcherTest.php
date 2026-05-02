<?php

namespace App\Tests\LastFm;

use App\Entity\LastFmAlias;
use App\LastFm\LastFmScrobble;
use App\LastFm\MatchResult;
use App\LastFm\ScrobbleMatcher;
use App\Navidrome\NavidromeRepository;
use App\Tests\Navidrome\NavidromeFixtureFactory;
use PHPUnit\Framework\TestCase;

class ScrobbleMatcherTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/navidrome-matcher-' . uniqid() . '.db';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testFallsBackToArtistTitleAlbumTriplet(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-album', 'One More Time', 'Daft Punk', album: 'Discovery');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-single', 'One More Time', 'Daft Punk', album: 'One More Time');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $matcher = new ScrobbleMatcher($repo);

        $r = $matcher->match(new LastFmScrobble('Daft Punk', 'One More Time', 'Discovery', null, new \DateTimeImmutable()));
        $this->assertSame('mf-album', $r->mediaFileId);
    }

    public function testFallsBackToArtistTitleCouple(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Take Me to Church', 'Hozier');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $matcher = new ScrobbleMatcher($repo);

        $r = $matcher->match(new LastFmScrobble('Hozier', 'Take Me to Church', '', null, new \DateTimeImmutable()));
        $this->assertSame('mf-1', $r->mediaFileId);
    }

    public function testReturnsUnmatchedWhenNothingFits(): void
    {
        NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $matcher = new ScrobbleMatcher($repo);

        $r = $matcher->match(new LastFmScrobble('Unknown', 'Nothing', '', null, new \DateTimeImmutable()));
        $this->assertSame(MatchResult::STATUS_UNMATCHED, $r->status);
        $this->assertNull($r->mediaFileId);
    }

    public function testFuzzyOptInOnly(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Take Me to Church', 'Hozier');

        $repo = new NavidromeRepository($this->dbPath, 'admin');

        // Without fuzzy: typo unmatched.
        $matcher = new ScrobbleMatcher($repo);
        $r = $matcher->match(new LastFmScrobble('Hosier', 'Take Me to Church', '', null, new \DateTimeImmutable()));
        $this->assertSame(MatchResult::STATUS_UNMATCHED, $r->status);

        // With fuzzy=2: matched.
        $matcher = new ScrobbleMatcher($repo, null, 2);
        $r = $matcher->match(new LastFmScrobble('Hosier', 'Take Me to Church', '', null, new \DateTimeImmutable()));
        $this->assertSame(MatchResult::STATUS_MATCHED, $r->status);
        $this->assertSame('mf-1', $r->mediaFileId);
    }

    public function testManualAliasOverridesEverything(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-natural', 'Track', 'Bad Spelling');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-target', 'Track', 'Other Artist');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $aliasRepo = new InMemoryAliasRepository([new LastFmAlias('Bad Spelling', 'Track', 'mf-target')]);
        $matcher = new ScrobbleMatcher($repo, $aliasRepo);

        $r = $matcher->match(new LastFmScrobble('Bad Spelling', 'Track', '', null, new \DateTimeImmutable()));
        $this->assertSame('mf-target', $r->mediaFileId);
    }

    public function testManualAliasNullTargetReturnsSkipped(): void
    {
        NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $aliasRepo = new InMemoryAliasRepository([new LastFmAlias('Some Podcast', 'Episode 42', null)]);
        $matcher = new ScrobbleMatcher($repo, $aliasRepo);

        $r = $matcher->match(new LastFmScrobble('Some Podcast', 'Episode 42', '', null, new \DateTimeImmutable()));
        $this->assertSame(MatchResult::STATUS_SKIPPED, $r->status);
        $this->assertNull($r->mediaFileId);
    }
}
