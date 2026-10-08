<?php

namespace App\Tests\Repository;

use App\AudioMuse\TrackFeatures;
use App\Entity\AudioFeature;
use App\Repository\AudioFeatureRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

/** AudioFeatureRepository against a real in-memory SQLite EntityManager. */
class AudioFeatureRepositoryTest extends TestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfig([__DIR__ . '/../../src/Entity'], true);
        $config->setProxyDir(sys_get_temp_dir() . '/nd-orm-proxies');
        $config->setProxyNamespace('NdTestProxies');
        // Same column naming as the app (doctrine.yaml: underscore_number_aware).
        $config->setNamingStrategy(new UnderscoreNamingStrategy(\CASE_LOWER));
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->em = new EntityManager($connection, $config);
        (new SchemaTool($this->em))->createSchema([$this->em->getClassMetadata(AudioFeature::class)]);
    }

    private function repo(): AudioFeatureRepository
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->em);

        return new AudioFeatureRepository($registry);
    }

    /** @param array<string, float> $moods */
    private static function track(string $id, string $fp, ?float $tempo = null, ?float $energy = null, array $moods = []): TrackFeatures
    {
        return new TrackFeatures($id, $fp, $tempo, 'C', 'major', $energy, $moods, ['rock' => 0.5, 'pop' => 0.2]);
    }

    public function testUpsertInsertsThenUpdatesAndMapsThroughTheEntity(): void
    {
        $repo = $this->repo();
        $now = new \DateTimeImmutable('2026-10-08 06:00:00');

        $repo->upsert([self::track('a', 'fp1', 120.0), self::track('b', 'fp1')], $now);
        $repo->upsert([self::track('a', 'fp2', 121.5, 0.8)], $now);

        $this->assertSame(['a' => 'fp2', 'b' => 'fp1'], $repo->fingerprints());
        $this->assertSame(2, $repo->countAll());

        $this->em->clear();
        $a = $repo->find('a');
        $this->assertInstanceOf(AudioFeature::class, $a);
        $this->assertSame(121.5, $a->getTempo());
        $this->assertSame(0.8, $a->getEnergy());
        $this->assertSame('rock', $a->getTopGenre());
        $this->assertSame(['rock' => 0.5, 'pop' => 0.2], $a->getGenres());
    }

    public function testQueriesByTempoMoodAndEnergy(): void
    {
        $repo = $this->repo();
        $repo->upsert([
            self::track('fast', 'f', 170.0, 0.9, ['relaxed' => 0.1]),
            self::track('half', 'f', 85.0, 0.4, ['relaxed' => 0.7]),
            self::track('slow', 'f', 120.0, null, ['relaxed' => 0.95]),
            self::track('none', 'f'),
        ], new \DateTimeImmutable());

        $ids = $repo->idsInTempoRanges([[160.0, 180.0], [80.0, 90.0]]);
        sort($ids);
        $this->assertSame(['fast', 'half'], $ids);

        $this->assertSame(['slow', 'half'], $repo->topByMood('relaxed', 0.5, 10));
        $this->assertSame(['slow'], $repo->topByMood('relaxed', 0.5, 1));
        $this->assertSame(['fast' => 0.9, 'half' => 0.4], $repo->energyByIds(['fast', 'half', 'slow', 'unknown']));

        $this->assertSame(2, $repo->deleteIds(['fast', 'none', 'unknown']));
        $this->assertSame(2, $repo->countAll());
    }

    public function testTopByMoodRejectsUnknownMood(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repo()->topByMood('tempo; DROP TABLE audio_feature', 0.5, 1);
    }

    public function testFeaturesByIdsAndEnergyPercentile(): void
    {
        $repo = $this->repo();
        $this->assertNull($repo->energyPercentile(60));

        $tracks = [];
        foreach ([0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9, 1.0] as $i => $energy) {
            $tracks[] = self::track('e' . $i, 'f', 100.0 + $i, $energy, ['danceable' => 0.3]);
        }
        $tracks[] = self::track('no-energy', 'f', 120.0);
        $repo->upsert($tracks, new \DateTimeImmutable());

        $this->assertSame(0.1, $repo->energyPercentile(0));
        $this->assertSame(0.6, $repo->energyPercentile(60));
        $this->assertSame(1.0, $repo->energyPercentile(100));

        $this->assertSame([
            'e2' => ['tempo' => 102.0, 'energy' => 0.3, 'danceable' => 0.3],
            'no-energy' => ['tempo' => 120.0, 'energy' => null, 'danceable' => null],
        ], $repo->featuresByIds(['e2', 'no-energy', 'unknown']));
    }
}
