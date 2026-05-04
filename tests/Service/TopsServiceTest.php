<?php

namespace App\Tests\Service;

use App\Entity\TopSnapshot;
use App\Navidrome\NavidromeRepository;
use App\Repository\TopSnapshotRepository;
use App\Service\TopsService;
use App\Tests\Navidrome\NavidromeFixtureFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class TopsServiceTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/navidrome-tops-' . uniqid() . '.db';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testNormalizeWindowRoundsToDayBoundaries(): void
    {
        $from = new \DateTimeImmutable('2026-01-15 14:32:00');
        $to = new \DateTimeImmutable('2026-01-20 09:00:00');

        [$nFrom, $nTo] = TopsService::normalizeWindow($from, $to);

        $this->assertSame('2026-01-15 00:00:00', $nFrom->format('Y-m-d H:i:s'));
        // to is rounded to start-of-next-day so [from, to) covers the 20th inclusive.
        $this->assertSame('2026-01-21 00:00:00', $nTo->format('Y-m-d H:i:s'));
    }

    public function testNormalizeWindowEnforcesAtLeastOneDay(): void
    {
        $same = new \DateTimeImmutable('2026-01-15 09:00:00');
        [$from, $to] = TopsService::normalizeWindow($same, $same);

        $this->assertSame('2026-01-15 00:00:00', $from->format('Y-m-d H:i:s'));
        $this->assertSame('2026-01-16 00:00:00', $to->format('Y-m-d H:i:s'));
    }

    public function testComputePopulatesAllSections(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Track A', 'Artist X', 180, 'Album X');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-2', 'Track B', 'Artist X', 180, 'Album X');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-3', 'Track C', 'Artist Y', 180, 'Album Y');

        for ($i = 0; $i < 4; $i++) {
            NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', '2026-01-' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) . ' 10:00:00');
        }
        for ($i = 0; $i < 2; $i++) {
            NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-2', '2026-01-' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) . ' 11:00:00');
        }
        for ($i = 0; $i < 3; $i++) {
            NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-3', '2026-01-' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) . ' 12:00:00');
        }

        $em = $this->createMock(EntityManagerInterface::class);
        $persisted = [];
        $em->method('persist')->willReturnCallback(function (object $e) use (&$persisted): void {
            $persisted[] = $e;
        });
        $em->method('flush');

        $repo = $this->createMock(TopSnapshotRepository::class);
        $repo->method('findOneByWindow')->willReturn(null);

        $navidrome = new NavidromeRepository($this->dbPath, 'admin');
        $service = new TopsService($navidrome, $repo, $em);

        $from = new \DateTimeImmutable('2026-01-01 00:00:00');
        $to = new \DateTimeImmutable('2026-01-31 00:00:00');
        $snapshot = $service->compute($from, $to);

        $this->assertCount(1, $persisted, 'snapshot persisted on first compute');
        $this->assertInstanceOf(TopSnapshot::class, $persisted[0]);

        $data = $snapshot->getData();
        $this->assertSame(9, $data['total_plays']);
        $this->assertSame(3, $data['distinct_tracks']);
        $this->assertCount(2, $data['top_artists']);
        $this->assertSame('Artist X', $data['top_artists'][0]['artist']);
        $this->assertSame(6, $data['top_artists'][0]['plays']);

        $this->assertCount(2, $data['top_albums']);
        $this->assertSame('Album X', $data['top_albums'][0]['album']);
        $this->assertSame(6, $data['top_albums'][0]['plays']);
        $this->assertSame(2, $data['top_albums'][0]['track_count']);

        $this->assertCount(3, $data['top_tracks']);
        $this->assertSame('mf-1', $data['top_tracks'][0]['id']);
        $this->assertSame(4, $data['top_tracks'][0]['plays']);
    }

    public function testComputeUpdatesExistingSnapshotWithoutPersisting(): void
    {
        NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        $em = $this->createMock(EntityManagerInterface::class);
        $persistCount = 0;
        $em->method('persist')->willReturnCallback(function () use (&$persistCount): void {
            $persistCount++;
        });
        $em->method('flush');

        $existing = new TopSnapshot(
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2026-02-01 00:00:00'),
            null,
        );
        $repo = $this->createMock(TopSnapshotRepository::class);
        $repo->method('findOneByWindow')->willReturn($existing);
        // Force an id so the service does NOT persist it as new.
        $reflect = new \ReflectionProperty(TopSnapshot::class, 'id');
        $reflect->setValue($existing, 42);

        $navidrome = new NavidromeRepository($this->dbPath, 'admin');
        $service = new TopsService($navidrome, $repo, $em);

        $service->compute(
            new \DateTimeImmutable('2026-01-01 00:00:00'),
            new \DateTimeImmutable('2026-01-31 00:00:00'),
        );

        $this->assertSame(0, $persistCount, 'existing snapshot must not be re-persisted');
    }
}
