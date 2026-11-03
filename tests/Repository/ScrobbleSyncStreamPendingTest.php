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
 * Issue #290: streamPending() must not keep a read cursor open while the
 * caller writes, or a concurrent writer makes SQLite fail with « database is
 * locked » at once. Real ORM on a SQLite FILE in rollback-journal mode, as
 * data.db in production.
 */
class ScrobbleSyncStreamPendingTest extends TestCase
{
    private string $path;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        if (!Type::hasType(UtcDateTimeImmutableType::NAME)) {
            Type::addType(UtcDateTimeImmutableType::NAME, UtcDateTimeImmutableType::class);
        }
        $this->path = sys_get_temp_dir() . '/stream-pending-' . uniqid() . '.db';
        $config = ORMSetup::createAttributeMetadataConfig([__DIR__ . '/../../src/Entity'], true);
        $config->setProxyDir(sys_get_temp_dir() . '/nd-orm-proxies');
        $config->setProxyNamespace('NdTestProxies');
        $config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->path], $config);
        $this->em = new EntityManager($connection, $config);
        (new SchemaTool($this->em))->createSchema([
            $this->em->getClassMetadata(Scrobble::class),
            $this->em->getClassMetadata(ScrobbleSync::class),
        ]);
        $connection->executeStatement('PRAGMA journal_mode = DELETE');
        $connection->executeStatement('CREATE TABLE oauth_token_stub (token TEXT)');

        // Five pending rows; two share the same second to exercise the id tie-break.
        foreach (['2024-01-03', '2024-01-01', '2024-01-02', '2024-01-02', '2024-01-05'] as $i => $day) {
            $scrobble = new Scrobble('alice', 'Artist', 'T' . $i, null, null, null, null, null, new \DateTimeImmutable($day . ' 10:00:00', new \DateTimeZone('UTC')));
            $this->em->persist($scrobble);
            $this->em->persist(new ScrobbleSync($scrobble, ScrobbleSync::TARGET_NAVIDROME));
        }
        $this->em->flush();
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        $this->em->getConnection()->close();
        foreach ([$this->path, $this->path . '-journal'] as $f) {
            if (file_exists($f)) {
                unlink($f);
            }
        }
    }

    public function testYieldsEveryPendingRowOldestFirstAcrossPages(): void
    {
        $titles = [];
        foreach ($this->repo()->streamPending(ScrobbleSync::TARGET_NAVIDROME, 0, 2) as $sync) {
            $titles[] = $sync->getScrobble()->getTitle();
        }

        $this->assertSame(['T1', 'T2', 'T3', 'T0', 'T4'], $titles);
    }

    public function testLimitCapsTheTotalAcrossPages(): void
    {
        $count = 0;
        foreach ($this->repo()->streamPending(ScrobbleSync::TARGET_NAVIDROME, 3, 2) as $sync) {
            $count++;
        }

        $this->assertSame(3, $count);
    }

    public function testAConcurrentWriterCanCommitWhileTheCallerIsIterating(): void
    {
        $other = new \PDO('sqlite:' . $this->path);
        $other->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $other->exec('PRAGMA busy_timeout = 0');

        $committed = 0;
        foreach ($this->repo()->streamPending(ScrobbleSync::TARGET_NAVIDROME, 0, 2) as $sync) {
            // An OAuth token issued mid-run: with a cursor still open here,
            // the COMMIT below fails with « database is locked ».
            $other->exec('BEGIN IMMEDIATE');
            $other->exec("INSERT INTO oauth_token_stub VALUES ('jeton')");
            $other->exec('COMMIT');
            $committed++;

            // And the caller writes too, like the sync's flush per batch.
            $sync->markUnmatched();
            $this->em->flush();
        }

        $this->assertSame(5, $committed);
        $this->assertSame(0, (int) $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM scrobble_sync WHERE status = 'pending'"));
    }

    private function repo(): ScrobbleSyncRepository
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->em);

        return new ScrobbleSyncRepository($registry, $this->em);
    }
}
