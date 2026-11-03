<?php

namespace App\Tests\Service;

use App\Navidrome\NavidromeRepository;
use App\Repository\ScrobbleSyncRepository;
use App\Service\TargetIdRepairer;
use App\Tests\Navidrome\NavidromeFixtureFactory;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

/**
 * Issue #291: written rows whose recorded target left the library are
 * re-pointed at the track Navidrome holds the play under, same second.
 */
class TargetIdRepairerTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/nd-repair-' . uniqid() . '.db';
        $conn = NavidromeFixtureFactory::createDatabase($this->path, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'new-album', 'Petit Frère', 'IAM');
        NavidromeFixtureFactory::insertTrack($conn, 'new-other', 'La Saga', 'IAM');
        NavidromeFixtureFactory::insertTrack($conn, 'kept', 'Regarde', 'IAM');
        foreach (
            [
            ['new-album', '2024-01-01 10:00:00'],
            ['new-album', '2024-01-02 10:00:00'],
            ['new-other', '2024-01-02 10:00:00'], // same second, other track → ambiguous
            ['kept', '2024-01-04 10:00:00'],
            ] as [$mfid, $at]
        ) {
            $conn->executeStatement(
                'INSERT INTO scrobbles (media_file_id, user_id, submission_time) VALUES (?, ?, ?)',
                [$mfid, 'user-1', (new \DateTimeImmutable($at, new \DateTimeZone('UTC')))->getTimestamp()],
            );
        }
        $conn->close();
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path . '-wal', $this->path . '-shm'] as $f) {
            if (file_exists($f)) {
                unlink($f);
            }
        }
    }

    public function testRepairsOnlyUnambiguousStaleTargets(): void
    {
        $rows = [
            ['id' => 1, 'played_at' => '2024-01-01 10:00:00', 'target_id' => 'gone-1', 'artist' => 'IAM', 'title' => 'Petit frère'],
            ['id' => 2, 'played_at' => '2024-01-02 10:00:00', 'target_id' => 'gone-2', 'artist' => 'IAM', 'title' => 'La saga'],
            ['id' => 3, 'played_at' => '2024-01-03 10:00:00', 'target_id' => 'gone-3', 'artist' => 'IAM', 'title' => 'Nés sous la même étoile'],
            ['id' => 4, 'played_at' => '2024-01-04 10:00:00', 'target_id' => 'kept', 'artist' => 'IAM', 'title' => 'Regarde'],
        ];
        $written = new \ArrayObject();
        $syncRepo = $this->createMock(ScrobbleSyncRepository::class);
        $syncRepo->method('writtenNavidromePage')->willReturnCallback(
            static fn (int $after) => array_values(array_filter($rows, static fn (array $r) => $r['id'] > $after)),
        );
        $syncRepo->method('updateTargetIds')->willReturnCallback(static function (array $t) use ($written): int {
            foreach ($t as $id => $target) {
                $written[$id] = $target;
            }

            return count($t);
        });

        $repairer = new TargetIdRepairer($syncRepo, new NavidromeRepository($this->path, 'admin'));

        $dry = $repairer->run(true);
        $this->assertSame([4, 3, 1, 1, 1, 0], [$dry['examined'], $dry['stale'], $dry['repaired'], $dry['not_found'], $dry['ambiguous'], $dry['title_mismatch']]);
        $this->assertSame([], $written->getArrayCopy());
        $this->assertSame('new-album', $dry['samples'][0]['new_target']);

        $repairer->run(false);
        $this->assertSame([1 => 'new-album'], $written->getArrayCopy());
    }

    public function testUpdateTargetIdsRewritesOnlyTheTargetColumn(): void
    {
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $conn->executeStatement("CREATE TABLE scrobble_sync (id INTEGER PRIMARY KEY, target_id VARCHAR(255), status VARCHAR(16), strategy VARCHAR(32))");
        $conn->executeStatement("INSERT INTO scrobble_sync VALUES (1, 'gone', 'matched', 'couple'), (2, 'other', 'matched', 'triplet')");
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);
        $registry = $this->createMock(\Doctrine\Persistence\ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);
        $em->method('getClassMetadata')->willReturn(new \Doctrine\ORM\Mapping\ClassMetadata(\App\Entity\ScrobbleSync::class));

        $this->assertSame(1, (new ScrobbleSyncRepository($registry, $em))->updateTargetIds([1 => 'new']));
        $this->assertSame(
            [['id' => 1, 'target_id' => 'new', 'status' => 'matched', 'strategy' => 'couple'], ['id' => 2, 'target_id' => 'other', 'status' => 'matched', 'strategy' => 'triplet']],
            array_map(static fn (array $r) => ['id' => (int) $r['id']] + $r, $conn->fetchAllAssociative('SELECT * FROM scrobble_sync ORDER BY id')),
        );
    }
}
