<?php

namespace App\Tests\Service;

use App\Entity\StatsSnapshot;
use App\Navidrome\NavidromeRepository;
use App\Repository\StatsHistoryRepository;
use App\Repository\StatsSnapshotRepository;
use App\Service\DisparityStatsService;
use App\Service\NavidromeStatsService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class NavidromeStatsServiceTest extends TestCase
{
    public function testGetAttachesLibraryHistorySeries(): void
    {
        $snapshot = new StatsSnapshot(NavidromeStatsService::SNAPSHOT_KEY);
        $snapshot->setData(['library' => ['tracks' => 100, 'artists' => 10, 'albums' => 20, 'duration_seconds' => 3600]]);

        $snapshots = $this->createMock(StatsSnapshotRepository::class);
        $snapshots->method('findOneByPeriod')->willReturn($snapshot);

        $series = [
            ['day' => '2026-06-28', 'tracks' => 90, 'artists' => 9, 'albums' => 18, 'duration_seconds' => 3000],
            ['day' => '2026-06-29', 'tracks' => 100, 'artists' => 10, 'albums' => 20, 'duration_seconds' => 3600],
        ];
        $history = $this->createMock(StatsHistoryRepository::class);
        $history->method('series')->willReturn($series);

        $service = new NavidromeStatsService(
            $this->createMock(NavidromeRepository::class),
            $snapshots,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(DisparityStatsService::class),
            $history,
        );

        $data = $service->get();

        $this->assertNotNull($data);
        $this->assertSame($series, $data['library_history']);
        // Existing payload still flows through untouched.
        $this->assertSame(100, $data['library']['tracks']);
    }

    public function testGetReturnsNullWhenNoSnapshot(): void
    {
        $snapshots = $this->createMock(StatsSnapshotRepository::class);
        $snapshots->method('findOneByPeriod')->willReturn(null);

        $history = $this->createMock(StatsHistoryRepository::class);
        $history->expects($this->never())->method('series');

        $service = new NavidromeStatsService(
            $this->createMock(NavidromeRepository::class),
            $snapshots,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(DisparityStatsService::class),
            $history,
        );

        $this->assertNull($service->get());
    }
}
