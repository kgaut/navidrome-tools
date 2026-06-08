<?php

namespace App\Tests\Navidrome;

use App\Navidrome\NavidromeRepository;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Locks in the schema-agnostic annotation INSERT (fix #184) on the two
 * variants of Navidrome's `annotation` table : with `ann_id` (older) and
 * without (mid-2025+, identity = UNIQUE composite). Same code path,
 * both INSERTs must land cleanly.
 *
 * Also covers reconcileAnnotationForMediaFiles() — the bridge between
 * the `scrobbles` table populated by `app:scrobbles:sync-navidrome` and
 * the `annotation.play_count` column the Navidrome UI actually reads.
 */
class NavidromeAnnotationTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            foreach ([$path, $path . '-wal', $path . '-shm'] as $f) {
                if (file_exists($f)) {
                    unlink($f);
                }
            }
        }
    }

    /**
     * @return array{0: NavidromeRepository, 1: string}
     */
    private function setupRepo(bool $withAnnId): array
    {
        $path = sys_get_temp_dir() . '/nd-ann-' . uniqid() . '.db';
        $this->cleanup[] = $path;
        $conn = NavidromeFixtureFactory::createDatabase($path, withScrobbles: true, withAnnId: $withAnnId);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Get Lucky', 'Daft Punk');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-2', 'Da Funk', 'Daft Punk');
        $conn->close();

        return [new NavidromeRepository($path, 'admin'), $path];
    }

    #[DataProvider('schemas')]
    public function testMarkStarredInsertsRowOnFreshAnnotation(bool $withAnnId): void
    {
        [$repo, $path] = $this->setupRepo($withAnnId);

        $this->assertTrue($repo->markStarred('mf-1'));

        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
        $row = $conn->fetchAssociative(
            "SELECT user_id, item_id, item_type, starred, starred_at FROM annotation
              WHERE item_id = 'mf-1'",
        );
        $this->assertSame('user-1', $row['user_id']);
        $this->assertSame(1, (int) $row['starred']);
        $this->assertNotNull($row['starred_at']);
        $conn->close();
    }

    #[DataProvider('schemas')]
    public function testMarkStarredPromotesExistingRowFromPlaycountOnly(bool $withAnnId): void
    {
        [$repo, $path] = $this->setupRepo($withAnnId);

        // Pre-seed an annotation row with play_count=5 but starred=0.
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
        NavidromeFixtureFactory::insertAnnotation($conn, 'user-1', 'mf-1', playCount: 5, playDate: '2024-01-01 12:00:00');

        $this->assertTrue($repo->markStarred('mf-1'));

        $row = $conn->fetchAssociative("SELECT play_count, starred FROM annotation WHERE item_id = 'mf-1'");
        $this->assertSame(5, (int) $row['play_count'], 'play_count must be preserved');
        $this->assertSame(1, (int) $row['starred']);
        $conn->close();
    }

    #[DataProvider('schemas')]
    public function testMarkStarredIsIdempotentOnAlreadyStarred(bool $withAnnId): void
    {
        [$repo, $path] = $this->setupRepo($withAnnId);
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
        NavidromeFixtureFactory::insertAnnotation($conn, 'user-1', 'mf-1', playCount: 0, playDate: '2024-01-01 12:00:00', starred: 1, starredAt: '2024-01-01 00:00:00');
        $conn->close();

        // Second call must not flip any row, and must not raise « table
        // annotation has no column named ann_id » on the ann_id-less schema.
        $this->assertFalse($repo->markStarred('mf-1'));
    }

    #[DataProvider('schemas')]
    public function testReconcileAnnotationCreatesRowsForFreshMediaFiles(bool $withAnnId): void
    {
        [$repo, $path] = $this->setupRepo($withAnnId);
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', '2024-01-01 10:00:00');
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', '2024-01-02 10:00:00');
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-2', '2024-01-03 10:00:00');
        $conn->close();

        $touched = $repo->reconcileAnnotationForMediaFiles('user-1', ['mf-1', 'mf-2']);
        $this->assertSame(2, $touched);

        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
        $rows = $conn->fetchAllAssociativeIndexed(
            "SELECT item_id, play_count, play_date FROM annotation",
        );
        $this->assertSame(2, (int) $rows['mf-1']['play_count']);
        $this->assertSame(1, (int) $rows['mf-2']['play_count']);
        // play_date is taken from datetime(MAX(submission_time), 'unixepoch').
        $this->assertSame('2024-01-02 10:00:00', $rows['mf-1']['play_date']);
        $conn->close();
    }

    #[DataProvider('schemas')]
    public function testReconcileAnnotationUpdatesExistingRowsWithoutDuplicating(bool $withAnnId): void
    {
        [$repo, $path] = $this->setupRepo($withAnnId);
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
        // Pre-existing annotation with a stale play_count — typically what
        // a wipe-scrobbles left behind (count=0) before re-import.
        NavidromeFixtureFactory::insertAnnotation($conn, 'user-1', 'mf-1', playCount: 0, playDate: '1970-01-01 00:00:00');
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', '2024-06-15 10:00:00');
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', '2024-06-16 10:00:00');
        $conn->close();

        $repo->reconcileAnnotationForMediaFiles('user-1', ['mf-1']);

        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
        $rows = $conn->fetchAllAssociative("SELECT play_count, play_date FROM annotation WHERE item_id = 'mf-1'");
        $this->assertCount(1, $rows, 'No duplicate annotation row');
        $this->assertSame(2, (int) $rows[0]['play_count']);
        $this->assertSame('2024-06-16 10:00:00', $rows[0]['play_date']);
        $conn->close();
    }

    public function testReconcileNoopOnEmptyMediaFileList(): void
    {
        [$repo, $_] = $this->setupRepo(withAnnId: false);
        $this->assertSame(0, $repo->reconcileAnnotationForMediaFiles('user-1', []));
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function schemas(): array
    {
        return [
            'older schema with ann_id' => [true],
            'recent schema without ann_id (fix #184)' => [false],
        ];
    }
}
