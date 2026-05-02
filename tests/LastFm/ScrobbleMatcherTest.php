<?php

namespace App\Tests\LastFm;

use App\Entity\LastFmAlias;
use App\Entity\LastFmArtistAlias;
use App\Entity\LastFmMatchCacheEntry;
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

    public function testArtistAliasRewritesNameAndCascadeMatches(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        // Lib only has tracks under the canonical name "La Ruda".
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'L\'instinct du meilleur', 'La Ruda');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-2', 'Le prix du silence', 'La Ruda');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $artistAliasRepo = new InMemoryArtistAliasRepository([
            new LastFmArtistAlias('La Ruda Salska', 'La Ruda'),
        ]);
        $matcher = new ScrobbleMatcher($repo, null, 0, $artistAliasRepo);

        // Last.fm scrobble uses the old name → strict match would fail, alias rewrites it.
        $r = $matcher->match(new LastFmScrobble('La Ruda Salska', 'L\'instinct du meilleur', '', null, new \DateTimeImmutable()));
        $this->assertSame(MatchResult::STATUS_MATCHED, $r->status);
        $this->assertSame('mf-1', $r->mediaFileId);

        // Same alias covers ANOTHER track of the same artist.
        $r = $matcher->match(new LastFmScrobble('La Ruda Salska', 'Le prix du silence', '', null, new \DateTimeImmutable()));
        $this->assertSame('mf-2', $r->mediaFileId);
    }

    public function testArtistAliasSkippedWhenNotConfigured(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Track', 'La Ruda');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        // No artist alias repo → "La Ruda Salska" stays as-is, strict match fails.
        $matcher = new ScrobbleMatcher($repo);

        $r = $matcher->match(new LastFmScrobble('La Ruda Salska', 'Track', '', null, new \DateTimeImmutable()));
        $this->assertSame(MatchResult::STATUS_UNMATCHED, $r->status);
    }

    public function testTrackAliasTakesPriorityOverArtistAlias(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-canonical', 'Track', 'La Ruda');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-track-override', 'Track', 'Other Artist');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        // Track-level alias forces this scrobble to mf-track-override.
        $trackAliasRepo = new InMemoryAliasRepository([
            new LastFmAlias('La Ruda Salska', 'Track', 'mf-track-override'),
        ]);
        // Artist-level alias would rewrite to "La Ruda" → mf-canonical.
        $artistAliasRepo = new InMemoryArtistAliasRepository([
            new LastFmArtistAlias('La Ruda Salska', 'La Ruda'),
        ]);

        $matcher = new ScrobbleMatcher($repo, $trackAliasRepo, 0, $artistAliasRepo);
        $r = $matcher->match(new LastFmScrobble('La Ruda Salska', 'Track', '', null, new \DateTimeImmutable()));

        // Track-level wins.
        $this->assertSame('mf-track-override', $r->mediaFileId);
    }

    public function testArtistAliasIsCaseAndAccentInsensitive(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Track', 'Tchaikovsky');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $artistAliasRepo = new InMemoryArtistAliasRepository([
            // Stored with accent + capitalization.
            new LastFmArtistAlias('Tchaïkovski', 'Tchaikovsky'),
        ]);
        $matcher = new ScrobbleMatcher($repo, null, 0, $artistAliasRepo);

        // Lookup with different casing AND missing accent (matches via np_normalize).
        $r = $matcher->match(new LastFmScrobble('TCHAIKOVSKI', 'Track', '', null, new \DateTimeImmutable()));
        $this->assertSame('mf-1', $r->mediaFileId);
    }

    public function testCacheMissPersistsPositiveAndShortCircuitsNextCall(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Take Me to Church', 'Hozier');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $cacheRepo = new InMemoryLastFmMatchCacheRepository();
        $matcher = new ScrobbleMatcher($repo, null, 0, null, $cacheRepo);

        // First call : cache miss, cascade matches via couple, result persisted.
        $r1 = $matcher->match(new LastFmScrobble('Hozier', 'Take Me to Church', '', null, new \DateTimeImmutable()));
        $this->assertSame('mf-1', $r1->mediaFileId);
        $this->assertSame(MatchResult::CACHE_MISS, $r1->cacheStatus);
        $this->assertSame(LastFmMatchCacheEntry::STRATEGY_COUPLE, $r1->strategy);
        $this->assertSame(1, $cacheRepo->size());

        // Second call : cache hit positive — no cascade, strategy carried over.
        $r2 = $matcher->match(new LastFmScrobble('Hozier', 'Take Me to Church', '', null, new \DateTimeImmutable()));
        $this->assertSame('mf-1', $r2->mediaFileId);
        $this->assertSame(MatchResult::CACHE_HIT_POSITIVE, $r2->cacheStatus);
        $this->assertSame(LastFmMatchCacheEntry::STRATEGY_COUPLE, $r2->strategy);
    }

    public function testCacheMissPersistsNegativeAndSubsequentCallsShortCircuit(): void
    {
        NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $cacheRepo = new InMemoryLastFmMatchCacheRepository();
        $matcher = new ScrobbleMatcher($repo, null, 0, null, $cacheRepo);

        // First call : nothing in lib, cascade returns nothing, negative cached.
        $r1 = $matcher->match(new LastFmScrobble('Unknown', 'Nothing', '', null, new \DateTimeImmutable()));
        $this->assertSame(MatchResult::STATUS_UNMATCHED, $r1->status);
        $this->assertSame(MatchResult::CACHE_MISS, $r1->cacheStatus);
        $this->assertSame(1, $cacheRepo->size());

        // Second call : negative cache hit, still unmatched but no cascade.
        $r2 = $matcher->match(new LastFmScrobble('Unknown', 'Nothing', '', null, new \DateTimeImmutable()));
        $this->assertSame(MatchResult::STATUS_UNMATCHED, $r2->status);
        $this->assertSame(MatchResult::CACHE_HIT_NEGATIVE, $r2->cacheStatus);
    }

    public function testStaleNegativeCacheEntryIsRetriedThroughCascade(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        // The track was just added to Navidrome — but the cache still
        // remembers the old « unmatched » verdict from a previous run.
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Take Me to Church', 'Hozier');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $cacheRepo = new InMemoryLastFmMatchCacheRepository();
        $cacheRepo->seed(
            new LastFmMatchCacheEntry('Hozier', 'Take Me to Church', null, LastFmMatchCacheEntry::STRATEGY_NEGATIVE),
            (new \DateTimeImmutable())->modify('-90 days'),
        );

        // TTL of 30 days — the seeded entry is stale, cascade re-runs and
        // overwrites the cache entry with the freshly matched id.
        $matcher = new ScrobbleMatcher($repo, null, 0, null, $cacheRepo, 30);
        $r = $matcher->match(new LastFmScrobble('Hozier', 'Take Me to Church', '', null, new \DateTimeImmutable()));
        $this->assertSame('mf-1', $r->mediaFileId);
        $this->assertSame(MatchResult::CACHE_MISS, $r->cacheStatus);
        $this->assertTrue($cacheRepo->findByCouple('Hozier', 'Take Me to Church')?->isPositive());
    }

    public function testTtlZeroNeverExpiresNegativesEvenForOldEntries(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Track', 'Artist');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $cacheRepo = new InMemoryLastFmMatchCacheRepository();
        $cacheRepo->seed(
            new LastFmMatchCacheEntry('Artist', 'Track', null, LastFmMatchCacheEntry::STRATEGY_NEGATIVE),
            (new \DateTimeImmutable())->modify('-3650 days'),
        );

        // TTL=0 → never expire. The cascade is bypassed even though the
        // track now exists in the lib.
        $matcher = new ScrobbleMatcher($repo, null, 0, null, $cacheRepo, 0);
        $r = $matcher->match(new LastFmScrobble('Artist', 'Track', '', null, new \DateTimeImmutable()));
        $this->assertSame(MatchResult::STATUS_UNMATCHED, $r->status);
        $this->assertSame(MatchResult::CACHE_HIT_NEGATIVE, $r->cacheStatus);
    }

    public function testCascadeStrategyMbidIsPersistedInCache(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-mbid', 'Track', 'Artist', mbzTrackId: 'abc-mbid');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $cacheRepo = new InMemoryLastFmMatchCacheRepository();
        $matcher = new ScrobbleMatcher($repo, null, 0, null, $cacheRepo);

        $r = $matcher->match(new LastFmScrobble('Artist', 'Track', '', 'abc-mbid', new \DateTimeImmutable()));
        $this->assertSame('mf-mbid', $r->mediaFileId);
        $this->assertSame(LastFmMatchCacheEntry::STRATEGY_MBID, $r->strategy);
        $this->assertSame(LastFmMatchCacheEntry::STRATEGY_MBID, $cacheRepo->findByCouple('Artist', 'Track')?->getStrategy());
    }
}
