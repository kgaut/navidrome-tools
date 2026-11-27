<?php

namespace App\Tests\Scrobble;

use App\Doctrine\UtcDateTimeImmutableType;
use App\Entity\Scrobble;
use App\LastFm\LastFmScrobble;
use App\Repository\ScrobbleRepository;
use App\Scrobble\ScrobbleFetcher;
use App\Scrobble\ScrobbleSourceInterface;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Issue #310: the fetcher is source-agnostic and records which service each
 * listen came from. Real ORM schema, so the `source` column mapping is covered.
 */
class ScrobbleFetcherTest extends TestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        if (!Type::hasType(UtcDateTimeImmutableType::NAME)) {
            Type::addType(UtcDateTimeImmutableType::NAME, UtcDateTimeImmutableType::class);
        }
        $config = ORMSetup::createAttributeMetadataConfig([__DIR__ . '/../../src/Entity'], true);
        $config->setProxyDir(sys_get_temp_dir() . '/nd-orm-proxies');
        $config->setProxyNamespace('NdTestProxies');
        $config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->em = new EntityManager($connection, $config);
        (new SchemaTool($this->em))->createSchema([$this->em->getClassMetadata(Scrobble::class)]);
    }

    public function testStoresTheSourceNameAndUserOfEachListen(): void
    {
        $report = $this->fetcher()->fetch($this->source('listenbrainz', 'bob', [
            $this->listen('Artist', 'One', '2026-12-01 10:00:00'),
            $this->listen('Artist', 'Two', '2026-12-01 10:05:00'),
        ]));

        self::assertSame(2, $report->inserted);
        $rows = $this->em->getConnection()->fetchAllAssociative('SELECT lastfm_user, source FROM scrobbles ORDER BY played_at');
        self::assertSame([
            ['lastfm_user' => 'bob', 'source' => 'listenbrainz'],
            ['lastfm_user' => 'bob', 'source' => 'listenbrainz'],
        ], $rows);
    }

    public function testRefetchingTheSameWindowCountsDuplicates(): void
    {
        $listens = [$this->listen('Artist', 'One', '2026-12-01 10:00:00')];
        $this->fetcher()->fetch($this->source('listenbrainz', 'bob', $listens));
        $report = $this->fetcher()->fetch($this->source('listenbrainz', 'bob', $listens));

        self::assertSame(0, $report->inserted);
        self::assertSame(1, $report->duplicates);
    }

    public function testDryRunWritesNothing(): void
    {
        $report = $this->fetcher()->fetch(
            $this->source('listenbrainz', 'bob', [$this->listen('Artist', 'One', '2026-12-01 10:00:00')]),
            dryRun: true,
        );

        self::assertSame(1, $report->inserted);
        self::assertSame(0, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM scrobbles'));
    }

    public function testDryRunForecastsDuplicatesAgainstTheTableAndWithinTheSource(): void
    {
        $this->fetcher()->fetch($this->source('lastfm', 'bob', [$this->listen('Artist', 'Stored', '2026-05-01 10:00:00')]));

        $report = $this->fetcher()->fetch($this->source('listenbrainz', 'bob', [
            $this->listen('Artist', 'Stored', '2026-05-01 10:00:00'),
            $this->listen('Artist', 'New', '2026-05-01 11:00:00'),
            $this->listen('Artist', 'New', '2026-05-01 11:00:00'),
        ]), dryRun: true);

        self::assertSame(3, $report->fetched);
        self::assertSame(1, $report->inserted);
        self::assertSame(2, $report->duplicates);
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM scrobbles'));
    }

    public function testExistingRowsDefaultToLastFm(): void
    {
        $this->em->persist(new Scrobble('alice', 'Artist', 'Old', null, null, null, null, null, new \DateTimeImmutable('2020-01-01')));
        $this->em->flush();

        self::assertSame('lastfm', $this->em->getConnection()->fetchOne('SELECT source FROM scrobbles'));
    }

    private function fetcher(): ScrobbleFetcher
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->em);

        return new ScrobbleFetcher(new ScrobbleRepository($registry));
    }

    /**
     * @param list<LastFmScrobble> $listens
     */
    private function source(string $name, string $user, array $listens): ScrobbleSourceInterface
    {
        return new class ($name, $user, $listens) implements ScrobbleSourceInterface {
            /**
             * @param list<LastFmScrobble> $listens
             */
            public function __construct(private string $name, private string $user, private array $listens)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function user(): string
            {
                return $this->user;
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function streamListens(?\DateTimeInterface $dateMin = null, ?\DateTimeInterface $dateMax = null): iterable
            {
                return $this->listens;
            }
        };
    }

    private function listen(string $artist, string $title, string $playedAt): LastFmScrobble
    {
        return new LastFmScrobble(
            artist: $artist,
            title: $title,
            album: '',
            mbid: null,
            playedAt: new \DateTimeImmutable($playedAt, new \DateTimeZone('UTC')),
        );
    }
}
