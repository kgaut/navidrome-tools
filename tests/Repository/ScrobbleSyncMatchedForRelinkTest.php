<?php

namespace App\Tests\Repository;

use App\Doctrine\UtcDateTimeImmutableType;
use App\Entity\Scrobble;
use App\Entity\ScrobbleSync;
use App\Repository\ScrobbleSyncRepository;
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
 * Issue #300: the relink filters on artist / title / album are exact
 * (case-insensitive) unless `contains` is asked for.
 */
class ScrobbleSyncMatchedForRelinkTest extends TestCase
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
        (new SchemaTool($this->em))->createSchema([
            $this->em->getClassMetadata(Scrobble::class),
            $this->em->getClassMetadata(ScrobbleSync::class),
        ]);

        foreach ([['ABC', 'Song'], ['abc', 'Other'], ['XABCY', 'Song']] as $i => [$artist, $title]) {
            $scrobble = new Scrobble('alice', $artist, $title, 'Album', null, null, null, null, new \DateTimeImmutable('2024-01-0' . ($i + 1), new \DateTimeZone('UTC')));
            $sync = new ScrobbleSync($scrobble, ScrobbleSync::TARGET_NAVIDROME);
            $sync->markMatched('mf-' . $i, 'couple');
            $this->em->persist($scrobble);
            $this->em->persist($sync);
        }
        $this->em->flush();
        $this->em->clear();
    }

    public function testArtistIsExactAndCaseInsensitiveByDefault(): void
    {
        $this->assertSame(['ABC', 'abc'], $this->artists($this->repo()->matchedForRelink(ScrobbleSync::TARGET_NAVIDROME, 'Abc', null, null, 10)));
        $this->assertSame([], $this->repo()->matchedForRelink(ScrobbleSync::TARGET_NAVIDROME, 'AB', null, null, 10));
        $this->assertSame(['ABC'], $this->artists($this->repo()->matchedForRelink(ScrobbleSync::TARGET_NAVIDROME, 'abc', 'song', null, 10)));
    }

    public function testContainsMatchesASubstring(): void
    {
        $this->assertSame(
            ['ABC', 'abc', 'XABCY'],
            $this->artists($this->repo()->matchedForRelink(ScrobbleSync::TARGET_NAVIDROME, 'abc', null, null, 10, 0, true)),
        );
    }

    /**
     * @param list<ScrobbleSync> $rows
     *
     * @return list<string>
     */
    private function artists(array $rows): array
    {
        return array_map(static fn (ScrobbleSync $s) => $s->getScrobble()->getArtist(), $rows);
    }

    private function repo(): ScrobbleSyncRepository
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->em);

        return new ScrobbleSyncRepository($registry, $this->em);
    }
}
