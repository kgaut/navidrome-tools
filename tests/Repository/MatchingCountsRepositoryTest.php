<?php

namespace App\Tests\Repository;

use App\Entity\ScrobbleSync;
use App\Repository\ScrobbleSyncRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Matching counts served by the MCP server (issue #276).
 */
class MatchingCountsRepositoryTest extends TestCase
{
    private EntityManager $em;
    private ScrobbleSyncRepository $repo;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfig([__DIR__ . '/../../src/Entity'], true);
        $config->setProxyDir(sys_get_temp_dir() . '/nd-orm-proxies');
        $config->setProxyNamespace('NdTestProxies');
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->em = new EntityManager($connection, $config);
        $connection->executeStatement(
            'CREATE TABLE scrobbles (id INTEGER PRIMARY KEY, lastfm_user VARCHAR(255) NOT NULL, artist VARCHAR(255) NOT NULL,
             title VARCHAR(255) NOT NULL, album VARCHAR(255), played_at DATETIME NOT NULL)',
        );
        $connection->executeStatement(
            "CREATE TABLE scrobble_sync (id INTEGER PRIMARY KEY AUTOINCREMENT, scrobble_id INTEGER NOT NULL,
             target VARCHAR(32) NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'pending', strategy VARCHAR(32))",
        );
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->em);
        $this->repo = new ScrobbleSyncRepository($registry, $this->em);

        // A/X unmatched ×2 (one group), A/Y unmatched, B/Z unmatched in 2023.
        $this->row(1, 'A', 'X', '2024-03-01', ScrobbleSync::STATUS_UNMATCHED);
        $this->row(2, 'A', 'X', '2024-03-02', ScrobbleSync::STATUS_UNMATCHED);
        $this->row(3, 'A', 'Y', '2024-03-03', ScrobbleSync::STATUS_UNMATCHED);
        $this->row(4, 'B', 'Z', '2023-05-01', ScrobbleSync::STATUS_UNMATCHED);
        $this->row(5, 'C', 'W', '2024-03-04', ScrobbleSync::STATUS_MATCHED, 'mbid');
        $this->row(6, 'C', 'V', '2024-03-05', ScrobbleSync::STATUS_MATCHED, 'couple');
        $this->row(7, 'C', 'U', '2024-03-06', ScrobbleSync::STATUS_MATCHED, 'couple');
    }

    public function testCountUnmatchedGroupsFollowsTheListFilters(): void
    {
        $this->assertSame(3, $this->repo->countUnmatchedGroups(ScrobbleSync::TARGET_NAVIDROME));
        $this->assertSame(2, $this->repo->countUnmatchedGroups(ScrobbleSync::TARGET_NAVIDROME, 'a'));
        $this->assertSame(1, $this->repo->countUnmatchedGroups(ScrobbleSync::TARGET_NAVIDROME, null, 'z'));
        $this->assertSame(2, $this->repo->countUnmatchedGroups(ScrobbleSync::TARGET_NAVIDROME, null, null, '2024-03'));
        $this->assertSame(0, $this->repo->countUnmatchedGroups(ScrobbleSync::TARGET_STRAWBERRY));
    }

    public function testCountByStatusAndStrategy(): void
    {
        $this->assertSame(['matched' => 3, 'unmatched' => 4], $this->repo->countByStatus(ScrobbleSync::TARGET_NAVIDROME));
        $this->assertSame(['couple' => 2, 'mbid' => 1], $this->repo->countMatchedByStrategy(ScrobbleSync::TARGET_NAVIDROME));
        $this->assertSame([], $this->repo->countByStatus(ScrobbleSync::TARGET_STRAWBERRY));
    }

    private function row(int $id, string $artist, string $title, string $day, string $status, ?string $strategy = null): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement(
            'INSERT INTO scrobbles (id, lastfm_user, artist, title, album, played_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$id, 'alice', $artist, $title, '', $day . ' 10:00:00'],
        );
        $conn->executeStatement(
            'INSERT INTO scrobble_sync (scrobble_id, target, status, strategy) VALUES (?, ?, ?, ?)',
            [$id, ScrobbleSync::TARGET_NAVIDROME, $status, $strategy],
        );
    }
}
