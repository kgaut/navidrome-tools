<?php

namespace App\Tests\Loves;

use App\Doctrine\UtcDateTimeImmutableType;
use App\Entity\Scrobble;
use App\LastFm\LastFmLovedTrack;
use App\Loves\LovedFlagsSync;
use App\Loves\LovesProviderInterface;
use App\Repository\ScrobbleRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Issue #318: one pass over `scrobbles`, same matching rule as before — the
 * MBID wins while it has unflagged rows, otherwise (artist, title) compared
 * like SQLite LOWER() (ASCII-only), scoped to the provider's user.
 */
class LovedFlagsSyncTest extends TestCase
{
    private Connection $conn;
    private ScrobbleRepository $repo;

    protected function setUp(): void
    {
        if (!Type::hasType(UtcDateTimeImmutableType::NAME)) {
            Type::addType(UtcDateTimeImmutableType::NAME, UtcDateTimeImmutableType::class);
        }
        $config = ORMSetup::createAttributeMetadataConfig([__DIR__ . '/../../src/Entity'], true);
        $config->setProxyDir(sys_get_temp_dir() . '/nd-orm-proxies');
        $config->setProxyNamespace('NdTestProxies');
        $config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
        (new SchemaTool($em))->createSchema([$em->getClassMetadata(Scrobble::class)]);
        $this->conn = $em->getConnection();

        $rows = [
            // [user, artist, title, mbid, loved]
            ['bob', 'Daft Punk', 'Da Funk', 'rec-1', false],
            ['bob', 'daft punk', 'DA FUNK', null, false],      // same key, no MBID
            ['bob', 'Air', 'La Femme d\'Argent', null, false],
            ['bob', 'Air', 'La Femme d\'Argent', null, true],  // already loved
            ['bob', 'Émilie', 'Été', null, false],             // non-ASCII: LOWER() does not fold É
            ['alice', 'Air', 'La Femme d\'Argent', null, false], // other user
        ];
        foreach ($rows as $i => [$user, $artist, $title, $mbid, $loved]) {
            $s = new Scrobble($user, $artist, $title, null, null, $mbid, null, null, new \DateTimeImmutable('2026-01-01 10:00:00 +' . $i . ' minutes'), $loved);
            $em->persist($s);
        }
        $em->flush();

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);
        $this->repo = new ScrobbleRepository($registry);
    }

    public function testFlagsByMbidFirstThenByKeyForTheProviderUserOnly(): void
    {
        $report = (new LovedFlagsSync($this->repo))->sync($this->provider([
            new LastFmLovedTrack('Daft Punk', 'Da Funk', 'rec-1', null), // MBID row unflagged → only that row
            new LastFmLovedTrack('AIR', 'la femme d\'argent', null, null), // key, one row left to flag
            new LastFmLovedTrack('émilie', 'été', null, null),            // é ≠ É for LOWER() → unmatched
            new LastFmLovedTrack('Nobody', 'Nothing', null, null),
        ]));

        $this->assertSame(4, $report->fetched);
        $this->assertSame(2, $report->matched);
        $this->assertSame(2, $report->unmatched);
        $this->assertSame(2, $report->updatedRows);
        $this->assertSame(
            [['bob', 'Daft Punk', 1], ['bob', 'daft punk', 0], ['bob', 'Air', 1], ['bob', 'Air', 1], ['bob', 'Émilie', 0], ['alice', 'Air', 0]],
            array_map(
                static fn (array $r): array => [$r['lastfm_user'], $r['artist'], (int) $r['loved']],
                $this->conn->fetchAllAssociative('SELECT lastfm_user, artist, loved FROM scrobbles ORDER BY id'),
            ),
        );
    }

    public function testFallsBackToTheKeyWhenEveryMbidRowIsAlreadyLoved(): void
    {
        $this->conn->executeStatement("UPDATE scrobbles SET loved = 1 WHERE mbid_track = 'rec-1'");

        $report = (new LovedFlagsSync($this->repo))->sync($this->provider([new LastFmLovedTrack('Daft Punk', 'Da Funk', 'rec-1', null)]));

        $this->assertSame(1, $report->updatedRows);
        $this->assertSame(0, (int) $this->conn->fetchOne("SELECT COUNT(*) FROM scrobbles WHERE artist LIKE 'daft%' AND loved = 0"));
    }

    public function testDryRunForecastsWithoutWriting(): void
    {
        $report = (new LovedFlagsSync($this->repo))->sync($this->provider([new LastFmLovedTrack('AIR', 'la femme d\'argent', null, null)]), dryRun: true);

        $this->assertSame(1, $report->matched);
        $this->assertSame(1, $report->updatedRows);
        $this->assertSame(1, (int) $this->conn->fetchOne("SELECT COUNT(*) FROM scrobbles WHERE lastfm_user = 'bob' AND artist = 'Air' AND loved = 0"));
    }

    /**
     * @param list<LastFmLovedTrack> $loved
     */
    private function provider(array $loved): LovesProviderInterface
    {
        $provider = $this->createMock(LovesProviderInterface::class);
        $provider->method('user')->willReturn('bob');
        $provider->method('iterateLoved')->willReturn($loved);

        return $provider;
    }
}
