<?php

namespace App\Tests\Repository;

use App\Entity\ScrobbleSync;
use App\Repository\ScrobbleSyncRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Covers the "Stats non-matchés" repository helpers: top unmatched artists /
 * albums and the period (YYYY / YYYY-MM) filtering, against a real in-memory
 * SQLite connection (raw tables, no kernel).
 */
class UnmatchedStatsRepositoryTest extends TestCase
{
    private EntityManagerInterface $em;
    private ScrobbleSyncRepository $repo;

    protected function setUp(): void
    {
        $config = ORMSetup::createAttributeMetadataConfig([__DIR__ . '/../../src/Entity'], true);
        $config->setProxyDir(sys_get_temp_dir() . '/nd-orm-proxies');
        $config->setProxyNamespace('NdTestProxies');
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->em = new EntityManager($connection, $config);

        $connection->executeStatement(<<<'SQL'
            CREATE TABLE scrobbles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                lastfm_user VARCHAR(255) NOT NULL,
                artist VARCHAR(255) NOT NULL,
                title VARCHAR(255) NOT NULL,
                album VARCHAR(255),
                album_artist VARCHAR(255),
                played_at DATETIME NOT NULL
            )
        SQL);
        $connection->executeStatement(<<<'SQL'
            CREATE TABLE scrobble_sync (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                scrobble_id INTEGER NOT NULL,
                target VARCHAR(32) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'pending'
            )
        SQL);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->em);
        $this->repo = new ScrobbleSyncRepository($registry, $this->em);

        // Fixtures: A/X unmatched ×2 (Mar 2024), C unmatched no album (Mar 2024),
        // B/Y unmatched (May 2023), A/X MATCHED (excluded).
        $this->scrobble(1, 'A', 'X', '', '2024-03-01 10:00:00');
        $this->scrobble(2, 'A', 'X', '', '2024-03-15 10:00:00');
        $this->scrobble(3, 'B', 'Y', 'BB', '2023-05-01 10:00:00');
        $this->scrobble(4, 'A', 'X', '', '2024-03-20 10:00:00');
        $this->scrobble(5, 'C', '', '', '2024-03-02 10:00:00');
        $this->sync(1, ScrobbleSync::STATUS_UNMATCHED);
        $this->sync(2, ScrobbleSync::STATUS_UNMATCHED);
        $this->sync(3, ScrobbleSync::STATUS_UNMATCHED);
        $this->sync(4, ScrobbleSync::STATUS_MATCHED);
        $this->sync(5, ScrobbleSync::STATUS_UNMATCHED);
    }

    public function testTopUnmatchedArtistsRanksByCountExcludingMatched(): void
    {
        $rows = $this->repo->topUnmatchedArtists(ScrobbleSync::TARGET_NAVIDROME, 10);

        $this->assertSame('A', $rows[0]['artist']);
        $this->assertSame(2, $rows[0]['count']); // scrobbles 1 & 2; 4 is matched
        $byArtist = array_column($rows, 'count', 'artist');
        $this->assertSame(['A' => 2, 'B' => 1, 'C' => 1], $byArtist);
    }

    public function testTopUnmatchedAlbumsUsesAlbumArtistFallbackAndSkipsEmptyAlbum(): void
    {
        $rows = $this->repo->topUnmatchedAlbums(ScrobbleSync::TARGET_NAVIDROME, 10);

        // X (artist A via fallback) count 2 first, then Y (album_artist BB) count 1.
        // C has no album → excluded.
        $this->assertSame(['album' => 'X', 'artist' => 'A', 'count' => 2], $rows[0]);
        $this->assertSame('Y', $rows[1]['album']);
        $this->assertSame('BB', $rows[1]['artist']);
        $this->assertCount(2, $rows);
    }

    public function testCountUnmatchedFilteredByPeriod(): void
    {
        $this->assertSame(4, $this->repo->countUnmatchedForTarget(ScrobbleSync::TARGET_NAVIDROME)); // 1,2,3,5
        $this->assertSame(3, $this->repo->countUnmatchedForTarget(ScrobbleSync::TARGET_NAVIDROME, '2024-03')); // 1,2,5
        $this->assertSame(3, $this->repo->countUnmatchedForTarget(ScrobbleSync::TARGET_NAVIDROME, '2024')); // 1,2,5
        $this->assertSame(1, $this->repo->countUnmatchedForTarget(ScrobbleSync::TARGET_NAVIDROME, '2023')); // 3
    }

    public function testAggregateUnmatchedFilteredByPeriod(): void
    {
        $rows = $this->repo->aggregateUnmatched(ScrobbleSync::TARGET_NAVIDROME, 50, 0, null, null, '2023');

        $this->assertCount(1, $rows);
        $this->assertSame('B', $rows[0]['artist']);
    }

    private function scrobble(int $id, string $artist, string $album, string $albumArtist, string $playedAt): void
    {
        $this->em->getConnection()->executeStatement(
            'INSERT INTO scrobbles (id, lastfm_user, artist, title, album, album_artist, played_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$id, 'alice', $artist, 'Title ' . $id, $album, $albumArtist, $playedAt],
        );
    }

    private function sync(int $scrobbleId, string $status): void
    {
        $this->em->getConnection()->executeStatement(
            'INSERT INTO scrobble_sync (scrobble_id, target, status) VALUES (?, ?, ?)',
            [$scrobbleId, ScrobbleSync::TARGET_NAVIDROME, $status],
        );
    }
}
