<?php

namespace App\Tests\Service;

use App\Entity\LastFmAlias;
use App\Entity\LastFmArtistAlias;
use App\Navidrome\NavidromeRepository;
use App\Repository\LastFmAliasRepository;
use App\Repository\LastFmArtistAliasRepository;
use App\Repository\LastFmMatchCacheRepository;
use App\Repository\ScrobbleSyncRepository;
use App\Service\AliasGenerationOptions;
use App\Service\AliasGenerator;
use App\Tests\Navidrome\NavidromeFixtureFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class AliasGeneratorTest extends TestCase
{
    private string $ndDbPath;
    private Connection $conn;
    /** @var list<object> */
    private array $persisted = [];

    protected function setUp(): void
    {
        $this->persisted = [];
        $this->ndDbPath = sys_get_temp_dir() . '/alias-gen-test-' . uniqid() . '.db';
        $this->conn = NavidromeFixtureFactory::createDatabase($this->ndDbPath, withScrobbles: false);
        // The MBID artist bridge reads the dedicated `artist` table.
        $this->conn->executeStatement(
            'CREATE TABLE artist (id VARCHAR(255) PRIMARY KEY NOT NULL, name VARCHAR(255) NOT NULL, mbz_artist_id VARCHAR(255) DEFAULT NULL)',
        );
    }

    protected function tearDown(): void
    {
        $this->conn->close();
        foreach ([$this->ndDbPath, $this->ndDbPath . '-wal', $this->ndDbPath . '-shm'] as $f) {
            if (file_exists($f)) {
                unlink($f);
            }
        }
    }

    public function testAlbumMbidExactCreatesTrackAlias(): void
    {
        // Library owns the album (by MBID) under a different artist string
        // ("OrelSan feat. Skread"); the plain (artist, title) cascade can't
        // match "Orelsan / Ensemble", but the album MBID + exact title can.
        NavidromeFixtureFactory::insertTrack($this->conn, 'mf-ens', 'Ensemble', 'OrelSan feat. Skread', mbzAlbumId: 'alb-1');

        $report = $this->makeGenerator(
            artistRows: [],
            coupleRows: [['artist' => 'Orelsan', 'title' => 'Ensemble', 'mbid_albums' => 'alb-1', 'plays' => 12]],
        )->generate(new AliasGenerationOptions());

        $this->assertSame(1, $report->trackAlbumExact);
        $this->assertSame(0, $report->trackAlbumFuzzy);
        $this->assertSame(1, $report->trackAliasesCreated());

        $alias = $this->onlyPersisted(LastFmAlias::class);
        $this->assertSame('Orelsan', $alias->getSourceArtist());
        $this->assertSame('Ensemble', $alias->getSourceTitle());
        $this->assertSame('mf-ens', $alias->getTargetMediaFileId());
    }

    public function testAlbumMbidFuzzyCreatesTrackAlias(): void
    {
        // Same album anchor, but the title needs a small Levenshtein hop
        // ("Burnin'" → "Burnin' Dub"). No exact title match, so it falls to
        // the fuzzy step.
        NavidromeFixtureFactory::insertTrack($this->conn, 'mf-burn', "Burnin' Dub", 'EZ3kiel feat. Sir Jean', mbzAlbumId: 'alb-2');

        $report = $this->makeGenerator(
            artistRows: [],
            coupleRows: [['artist' => 'EZ3kiel', 'title' => "Burnin'", 'mbid_albums' => 'alb-2', 'plays' => 15]],
        )->generate(new AliasGenerationOptions());

        $this->assertSame(0, $report->trackAlbumExact);
        $this->assertSame(1, $report->trackAlbumFuzzy);

        $alias = $this->onlyPersisted(LastFmAlias::class);
        $this->assertSame('mf-burn', $alias->getTargetMediaFileId());
    }

    public function testArtistMbidCreatesArtistAlias(): void
    {
        // Library owns the artist under its canonical name; Last.fm spells it
        // "MPL". The shared mbz_artist_id bridges the two.
        NavidromeFixtureFactory::insertTrack($this->conn, 'mf-mpl', 'Une chanson', 'Ma Pauvre Lucette');
        $this->conn->executeStatement(
            "INSERT INTO artist (id, name, mbz_artist_id) VALUES ('a-1', 'Ma Pauvre Lucette', 'mb-1')",
        );

        $report = $this->makeGenerator(
            artistRows: [['artist' => 'MPL', 'mbid_artist' => 'mb-1', 'plays' => 7]],
            coupleRows: [],
        )->generate(new AliasGenerationOptions());

        $this->assertSame(1, $report->artistAliasesCreated);

        $alias = $this->onlyPersisted(LastFmArtistAlias::class);
        $this->assertSame('MPL', $alias->getSourceArtist());
        $this->assertSame('Ma Pauvre Lucette', $alias->getTargetArtist());
    }

    public function testStaleCoupleResolvableByRematchIsSkipped(): void
    {
        // The exact normalized (artist, title) is already in the library — the
        // unmatched status is stale, a plain rematch resolves it, so no alias
        // should be created.
        NavidromeFixtureFactory::insertTrack($this->conn, 'mf-guts', 'Hip Hop First of All', 'Guts');

        $report = $this->makeGenerator(
            artistRows: [],
            coupleRows: [['artist' => 'Guts', 'title' => 'Hip Hop First of All', 'mbid_albums' => null, 'plays' => 50]],
        )->generate(new AliasGenerationOptions());

        $this->assertSame(1, $report->cascadeResolvable);
        $this->assertSame(0, $report->trackAliasesCreated());
        $this->assertSame([], $this->persisted);
    }

    public function testDryRunPersistsNothing(): void
    {
        NavidromeFixtureFactory::insertTrack($this->conn, 'mf-ens', 'Ensemble', 'OrelSan feat. Skread', mbzAlbumId: 'alb-1');

        $report = $this->makeGenerator(
            artistRows: [],
            coupleRows: [['artist' => 'Orelsan', 'title' => 'Ensemble', 'mbid_albums' => 'alb-1', 'plays' => 12]],
        )->generate(new AliasGenerationOptions(dryRun: true));

        $this->assertSame(1, $report->trackAliasesCreated());
        $this->assertSame([], $this->persisted, 'dry-run must not persist');
    }

    /**
     * @param list<array{artist: string, mbid_artist: string, plays: int}>             $artistRows
     * @param list<array{artist: string, title: string, mbid_albums: ?string, plays: int}> $coupleRows
     */
    private function makeGenerator(array $artistRows, array $coupleRows): AliasGenerator
    {
        $syncRepo = $this->createMock(ScrobbleSyncRepository::class);
        $syncRepo->method('unmatchedArtistMbids')->willReturn($artistRows);
        $syncRepo->method('unmatchedCouples')->willReturn($coupleRows);

        $aliasRepo = $this->createMock(LastFmAliasRepository::class);
        $aliasRepo->method('existingNormalizedKeys')->willReturn([]);

        $artistAliasRepo = $this->createMock(LastFmArtistAliasRepository::class);
        $artistAliasRepo->method('existingSourceNorms')->willReturn([]);

        $cacheRepo = $this->createMock(LastFmMatchCacheRepository::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $e): void {
            $this->persisted[] = $e;
        });

        $navidrome = new NavidromeRepository($this->ndDbPath, 'admin');

        return new AliasGenerator($navidrome, $syncRepo, $aliasRepo, $artistAliasRepo, $cacheRepo, $em);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function onlyPersisted(string $class): object
    {
        $matches = array_values(array_filter($this->persisted, static fn (object $e): bool => $e instanceof $class));
        $this->assertCount(1, $matches, sprintf('expected exactly one persisted %s', $class));

        return $matches[0];
    }
}
