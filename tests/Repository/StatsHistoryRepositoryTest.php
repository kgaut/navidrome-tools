<?php

namespace App\Tests\Repository;

use App\Entity\StatsHistory;
use App\Repository\StatsHistoryRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Exercises StatsHistoryRepository against a real in-memory SQLite ORM
 * EntityManager (no Symfony kernel) — so the upsert-per-day and ordering
 * behaviour is validated, not mocked.
 */
class StatsHistoryRepositoryTest extends TestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfig([__DIR__ . '/../../src/Entity'], true);
        $config->setProxyDir(sys_get_temp_dir() . '/nd-orm-proxies');
        $config->setProxyNamespace('NdTestProxies');
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->em = new EntityManager($connection, $config);

        $tool = new SchemaTool($this->em);
        $tool->createSchema([$this->em->getClassMetadata(StatsHistory::class)]);
    }

    private function repo(): StatsHistoryRepository
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->em);

        return new StatsHistoryRepository($registry, $this->em);
    }

    /**
     * @param array{tracks?: int, artists?: int, albums?: int, duration_seconds?: int} $overrides
     *
     * @return array{tracks: int, artists: int, albums: int, duration_seconds: int}
     */
    private static function counts(array $overrides = []): array
    {
        return $overrides + ['tracks' => 100, 'artists' => 10, 'albums' => 20, 'duration_seconds' => 3600];
    }

    public function testRecordDayCreatesOneRowPerDayAndUpsertsSameDay(): void
    {
        $repo = $this->repo();
        $day = new \DateTimeImmutable('2026-06-29 08:00:00');

        $repo->recordDay($day, self::counts(['tracks' => 100]));
        // Same calendar day, later run with a new value → updates, no duplicate.
        $repo->recordDay($day->setTime(20, 0), self::counts(['tracks' => 142]));

        $this->em->clear();
        $rows = $repo->findAll();
        $this->assertCount(1, $rows);
        $this->assertSame(142, $rows[0]->getTracks());
        $this->assertSame('2026-06-29', $rows[0]->getDay());
    }

    public function testSeriesReturnsChronologicalAscending(): void
    {
        $repo = $this->repo();
        $repo->recordDay(new \DateTimeImmutable('2026-06-29'), self::counts(['tracks' => 300, 'duration_seconds' => 9000]));
        $repo->recordDay(new \DateTimeImmutable('2026-06-27'), self::counts(['tracks' => 100]));
        $repo->recordDay(new \DateTimeImmutable('2026-06-28'), self::counts(['tracks' => 200, 'artists' => 42]));

        $series = $repo->series();

        $this->assertSame(['2026-06-27', '2026-06-28', '2026-06-29'], array_column($series, 'day'));
        $this->assertSame([100, 200, 300], array_column($series, 'tracks'));
        // All four metrics are exposed for the per-metric charts.
        $this->assertSame(42, $series[1]['artists']);
        $this->assertSame(9000, $series[2]['duration_seconds']);
    }

    public function testSeriesCapsToMaxDaysKeepingMostRecent(): void
    {
        $repo = $this->repo();
        foreach (['2026-06-01', '2026-06-02', '2026-06-03'] as $i => $d) {
            $repo->recordDay(new \DateTimeImmutable($d), self::counts(['tracks' => ($i + 1) * 10]));
        }

        $series = $repo->series(2);

        // Most recent 2 days, still ascending.
        $this->assertSame(['2026-06-02', '2026-06-03'], array_column($series, 'day'));
    }
}
