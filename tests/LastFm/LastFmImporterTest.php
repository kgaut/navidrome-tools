<?php

namespace App\Tests\LastFm;

use App\Entity\LastFmAlias;
use App\LastFm\LastFmImporter;
use App\LastFm\LastFmScrobble;
use App\LastFm\ScrobbleMatcher;
use App\Navidrome\NavidromeRepository;
use App\Tests\Navidrome\NavidromeFixtureFactory;
use PHPUnit\Framework\TestCase;

class LastFmImporterTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/navidrome-import-' . uniqid() . '.db';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testImportMatchesArtistTitleAndDedupes(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'One More Time', 'Daft Punk');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-2', 'Xtal', 'Aphex Twin');

        // Pre-existing scrobble: should be detected as duplicate.
        $existingTime = new \DateTimeImmutable('2024-06-01 12:00:00');
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', $existingTime->format('Y-m-d H:i:s'));

        $client = new FakeLastFmClient([
            // Should match mf-1, but ±60s of existingTime → duplicate.
            new LastFmScrobble('Daft Punk', 'One More Time', 'Discovery', null, $existingTime->modify('+30 seconds')),
            // Different time, should be inserted.
            new LastFmScrobble('Daft Punk', 'One More Time', 'Discovery', null, new \DateTimeImmutable('2024-07-15 09:00:00')),
            // Should match mf-2 (case-insensitive).
            new LastFmScrobble('aphex twin', 'XTAL', '', null, new \DateTimeImmutable('2024-08-20 10:00:00')),
            // Should NOT match anything.
            new LastFmScrobble('Unknown Artist', 'Unknown Track', '', null, new \DateTimeImmutable('2024-09-01 10:00:00')),
        ]);

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $importer = $this->makeImporter($client, $repo);
        $report = $importer->import('fake-key', 'fake-user');

        $this->assertSame(4, $report->fetched);
        $this->assertSame(2, $report->inserted);
        $this->assertSame(1, $report->duplicates);
        $this->assertSame(1, $report->unmatched);
        $this->assertSame(['Unknown Artist', 'Unknown Track', '', 1], [
            $report->unmatchedRanking()[0]['artist'],
            $report->unmatchedRanking()[0]['title'],
            $report->unmatchedRanking()[0]['album'],
            $report->unmatchedRanking()[0]['count'],
        ]);

        // Verify rows were actually inserted.
        $count = $conn->fetchOne('SELECT COUNT(*) FROM scrobbles WHERE user_id = ?', ['user-1']);
        $this->assertSame(3, (int) $count, '1 pre-existing + 2 inserted = 3 total');
    }

    public function testDryRunDoesNotWrite(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Track', 'Artist');

        $client = new FakeLastFmClient([
            new LastFmScrobble('Artist', 'Track', '', null, new \DateTimeImmutable('2024-06-01 12:00:00')),
        ]);

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $report = $this->makeImporter($client, $repo)->import('k', 'u', dryRun: true);

        $this->assertSame(1, $report->inserted, 'Report counts inserted even in dry-run');
        $count = $conn->fetchOne('SELECT COUNT(*) FROM scrobbles WHERE user_id = ?', ['user-1']);
        $this->assertSame(0, (int) $count, 'No row should have been written in dry-run mode');
    }

    public function testImportPrefersTripletLookupWhenAlbumDisambiguates(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        // Same song lives on the studio album AND on a single — the bare
        // (artist, title) couple is ambiguous. The album in the scrobble
        // tells us which row to credit.
        NavidromeFixtureFactory::insertTrack($conn, 'mf-album', 'One More Time', 'Daft Punk', album: 'Discovery');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-single', 'One More Time', 'Daft Punk', album: 'One More Time');

        $client = new FakeLastFmClient([
            new LastFmScrobble('Daft Punk', 'One More Time', 'Discovery', null, new \DateTimeImmutable('2024-06-01 12:00:00')),
            new LastFmScrobble('Daft Punk', 'One More Time', 'One More Time', null, new \DateTimeImmutable('2024-06-02 12:00:00')),
        ]);

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $events = [];
        $this->makeImporter($client, $repo)->import(
            'k',
            'u',
            dryRun: true,
            onScrobble: function (LastFmScrobble $s, string $status, ?string $mfid) use (&$events): void {
                $events[] = [$s->album, $mfid];
            },
        );

        $this->assertSame([
            ['Discovery', 'mf-album'],
            ['One More Time', 'mf-single'],
        ], $events);
    }

    public function testManualAliasOverridesAllHeuristics(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        // The « real » match by heuristics would be mf-real, but the user
        // has explicitly mapped (Bad Spelling, Track) → mf-target.
        NavidromeFixtureFactory::insertTrack($conn, 'mf-real', 'Track', 'Bad Spelling');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-target', 'Track', 'Other Artist');

        $alias = new LastFmAlias('Bad Spelling', 'Track', 'mf-target');
        $aliasRepo = new InMemoryAliasRepository([$alias]);

        $client = new FakeLastFmClient([
            new LastFmScrobble('Bad Spelling', 'Track', '', null, new \DateTimeImmutable('2024-06-01 12:00:00')),
        ]);

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $events = [];
        $this->makeImporter($client, $repo, aliasRepo: $aliasRepo)->import(
            'k',
            'u',
            dryRun: true,
            onScrobble: function (LastFmScrobble $s, string $status, ?string $mfid) use (&$events): void {
                $events[] = [$status, $mfid];
            },
        );

        $this->assertSame([['inserted', 'mf-target']], $events);
    }

    public function testManualAliasWithNullTargetSkipsScrobble(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-real', 'My Podcast Episode 42', 'Some Podcast');

        $alias = new LastFmAlias('Some Podcast', 'My Podcast Episode 42', null);
        $aliasRepo = new InMemoryAliasRepository([$alias]);

        $client = new FakeLastFmClient([
            new LastFmScrobble('Some Podcast', 'My Podcast Episode 42', '', null, new \DateTimeImmutable('2024-06-01 12:00:00')),
        ]);

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $report = $this->makeImporter($client, $repo, aliasRepo: $aliasRepo)->import('k', 'u', dryRun: true);

        $this->assertSame(0, $report->inserted);
        $this->assertSame(0, $report->unmatched);
        $this->assertSame(1, $report->skipped);
    }

    public function testFuzzyFallbackKicksInOnlyWhenEnabled(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Take Me to Church', 'Hozier');

        // Typo on artist name, no MBID, no album → exact heuristics all fail.
        $client = new FakeLastFmClient([
            new LastFmScrobble('Hosier', 'Take Me to Church', '', null, new \DateTimeImmutable('2024-06-01 10:00:00')),
        ]);

        $repo = new NavidromeRepository($this->dbPath, 'admin');

        // Without fuzzy: unmatched.
        $report = $this->makeImporter($client, $repo)->import('k', 'u', dryRun: true);
        $this->assertSame(1, $report->unmatched);
        $this->assertSame(0, $report->inserted);

        // With fuzzy max distance 2: matched.
        $report = $this->makeImporter($client, $repo, fuzzy: 2)->import('k', 'u', dryRun: true);
        $this->assertSame(0, $report->unmatched);
        $this->assertSame(1, $report->inserted);
    }

    public function testOnScrobbleCallbackFiresWithStatusAndMediaFileId(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Hit', 'Artist');
        $existing = new \DateTimeImmutable('2024-05-01 10:00:00');
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', $existing->format('Y-m-d H:i:s'));

        $client = new FakeLastFmClient([
            // Will match mf-1 within ±60s of pre-existing → duplicate.
            new LastFmScrobble('Artist', 'Hit', '', null, $existing->modify('+10 seconds')),
            // Different time → inserted.
            new LastFmScrobble('Artist', 'Hit', '', null, new \DateTimeImmutable('2024-06-01 10:00:00')),
            // Won't match.
            new LastFmScrobble('Nope', 'Nada', '', null, new \DateTimeImmutable('2024-07-01 10:00:00')),
        ]);

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $events = [];
        $this->makeImporter($client, $repo)->import(
            'k',
            'u',
            dryRun: true,
            onScrobble: function (LastFmScrobble $s, string $status, ?string $mfid) use (&$events): void {
                $events[] = [$s->title, $status, $mfid];
            },
        );

        $this->assertSame([
            ['Hit', 'duplicate', 'mf-1'],
            ['Hit', 'inserted', 'mf-1'],
            ['Nada', 'unmatched', null],
        ], $events);
    }

    public function testCacheCountersAreReportedAcrossScrobbles(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Hit', 'Artist');

        $client = new FakeLastFmClient([
            new LastFmScrobble('Artist', 'Hit', '', null, new \DateTimeImmutable('2024-06-01 10:00:00')),
            // Same couple → positive cache hit.
            new LastFmScrobble('Artist', 'Hit', '', null, new \DateTimeImmutable('2024-06-02 10:00:00')),
            // Different couple, never matchable → cache miss + negative cache.
            new LastFmScrobble('Unknown', 'Nothing', '', null, new \DateTimeImmutable('2024-06-03 10:00:00')),
            // Same unmatchable couple → negative cache hit.
            new LastFmScrobble('Unknown', 'Nothing', '', null, new \DateTimeImmutable('2024-06-04 10:00:00')),
        ]);

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $cache = new InMemoryLastFmMatchCacheRepository();
        $importer = $this->makeImporter($client, $repo, cache: $cache);
        $report = $importer->import('k', 'u', dryRun: true);

        $this->assertSame(2, $report->cacheMisses, 'first occurrence of each distinct couple');
        $this->assertSame(1, $report->cacheHitsPositive, 'second matchable scrobble');
        $this->assertSame(1, $report->cacheHitsNegative, 'second unmatchable scrobble');
    }

    private function makeImporter(
        FakeLastFmClient $client,
        NavidromeRepository $repo,
        ?InMemoryAliasRepository $aliasRepo = null,
        int $fuzzy = 0,
        ?InMemoryLastFmMatchCacheRepository $cache = null,
    ): LastFmImporter {
        $matcher = new ScrobbleMatcher($repo, $aliasRepo, $fuzzy, null, $cache);

        return new LastFmImporter($client, $repo, $matcher);
    }
}
