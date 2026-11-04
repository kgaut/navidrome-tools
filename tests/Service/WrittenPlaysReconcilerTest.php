<?php

namespace App\Tests\Service;

use App\Entity\ScrobbleSync;
use App\Navidrome\NavidromeRepository;
use App\Repository\ScrobbleSyncRepository;
use App\Service\WrittenPlaysReconciler;
use App\Tests\Navidrome\NavidromeFixtureFactory;
use PHPUnit\Framework\TestCase;

/**
 * Issue #298: after Navidrome lost writes (restore), data.db is brought
 * back in line with navidrome.db.
 */
class WrittenPlaysReconcilerTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/nd-reconcile-' . uniqid() . '.db';
        $conn = NavidromeFixtureFactory::createDatabase($this->path, withScrobbles: true);
        foreach (['album', 'instru', 'other-a', 'other-b'] as $id) {
            NavidromeFixtureFactory::insertTrack($conn, $id, 'T ' . $id, 'Artist');
        }
        foreach (
            [
            ['album', '2024-01-01 10:00:30'],   // in line within tolerance
            ['instru', '2024-01-02 10:00:00'],  // relink reverted: play back on the instrumental
            ['other-a', '2024-01-04 10:00:00'], // two tracks at that second → ambiguous
            ['other-b', '2024-01-04 10:00:00'],
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

    public function testRealignsDataDbOnNavidrome(): void
    {
        $rows = [
            ['id' => 1, 'played_at' => '2024-01-01 10:00:00', 'target_id' => 'album', 'artist' => 'A', 'title' => 'T'],
            ['id' => 2, 'played_at' => '2024-01-02 10:00:00', 'target_id' => 'album', 'artist' => 'A', 'title' => 'T'],
            ['id' => 3, 'played_at' => '2024-01-03 10:00:00', 'target_id' => 'album', 'artist' => 'A', 'title' => 'T'],
            ['id' => 4, 'played_at' => '2024-01-04 10:00:00', 'target_id' => 'album', 'artist' => 'A', 'title' => 'T'],
        ];
        $retargeted = new \ArrayObject();
        $requeued = new \ArrayObject();
        $syncRepo = $this->createMock(ScrobbleSyncRepository::class);
        $syncRepo->method('writtenNavidromePage')->willReturnCallback(
            static function (int $after, int $size, array $statuses) use ($rows): array {
                self::assertSame([ScrobbleSync::STATUS_MATCHED], $statuses);

                return array_values(array_filter($rows, static fn (array $r) => $r['id'] > $after));
            },
        );
        $syncRepo->method('updateTargetIds')->willReturnCallback(static function (array $t) use ($retargeted): int {
            foreach ($t as $id => $target) {
                $retargeted[$id] = $target;
            }

            return count($t);
        });
        $syncRepo->method('resetIdsToPending')->willReturnCallback(static function (array $ids) use ($requeued): int {
            foreach ($ids as $id) {
                $requeued[] = $id;
            }

            return count($ids);
        });

        $reconciler = new WrittenPlaysReconciler($syncRepo, new NavidromeRepository($this->path, 'admin'));

        $dry = $reconciler->run(true);
        $this->assertSame([4, 1, 1, 1, 1], [$dry['examined'], $dry['in_line'], $dry['retargeted'], $dry['requeued'], $dry['ambiguous']]);
        $this->assertSame([], $retargeted->getArrayCopy());
        $this->assertSame([], $requeued->getArrayCopy());

        $reconciler->run(false);
        $this->assertSame([2 => 'instru'], $retargeted->getArrayCopy());
        $this->assertSame([3], $requeued->getArrayCopy());
    }
}
