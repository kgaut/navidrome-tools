<?php

namespace App\Tests\Repository;

use App\Doctrine\UtcDateTimeImmutableType;
use App\Entity\Scrobble;
use App\Entity\ScrobbleSync;
use App\Repository\ScrobbleRepository;
use App\Service\LastFmStatsService;
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
 * Issue #310: the listening history spans every source and account. A null
 * user means the whole table — listens fetched from ListenBrainz under
 * another account name are no longer left out.
 */
class ScrobbleRepositoryHistoryTest extends TestCase
{
    private EntityManagerInterface $em;
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
        $this->em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
        (new SchemaTool($this->em))->createSchema([
            $this->em->getClassMetadata(Scrobble::class),
            $this->em->getClassMetadata(ScrobbleSync::class),
        ]);
        $utc = new \DateTimeZone('UTC');
        foreach ([['lastfm-name', 'A', '2026-11-26 10:00:00'], ['lb-name', 'B', '2026-11-26 11:00:00'], ['lb-name', 'B', '2026-11-26 12:00:00']] as [$user, $artist, $at]) {
            $this->em->persist(new Scrobble($user, $artist, 'T ' . $at, null, null, null, null, null, new \DateTimeImmutable($at, $utc)));
        }
        $this->em->flush();

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->em);
        $this->repo = new ScrobbleRepository($registry);
    }

    public function testNullUserAggregatesEveryAccount(): void
    {
        $from = new \DateTimeImmutable('2026-11-26 00:00:00', new \DateTimeZone('UTC'));
        $to = $from->modify('+1 day');

        $all = array_sum(array_column($this->repo->getDailyListening(null, $from, $to), 'tracks'));
        $one = array_sum(array_column($this->repo->getDailyListening('lb-name', $from, $to), 'tracks'));

        $this->assertSame(3, $all);
        $this->assertSame(2, $one);
        $this->assertSame(3, array_sum(array_column($this->repo->getDailyMatchedTargets(null, $from, $to), 'plays')));
        $this->assertSame('B', $this->repo->getTopArtistByDay(null, $from, $to)[0]['artist']);
    }

    public function testResolveUserNoLongerPicksTheDominantAccount(): void
    {
        $service = (new \ReflectionClass(LastFmStatsService::class))->newInstanceWithoutConstructor();

        $this->assertNull($service->resolveUser(null));
        $this->assertNull($service->resolveUser(''));
        $this->assertSame('lb-name', $service->resolveUser('lb-name'));
    }
}
