<?php

namespace App\Tests\Playlist;

use App\Navidrome\NavidromeRepository;
use App\Playlist\Definition\RetourEnArriereDefinition;
use App\Playlist\PlaylistContext;
use PHPUnit\Framework\TestCase;

class RetourEnArriereDefinitionTest extends TestCase
{
    public function testReturnsEmptyWhenNoScrobbleHistory(): void
    {
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->method('getScrobbleBounds')->willReturn(['first' => null, 'last' => null]);
        $navidrome->expects($this->never())->method('topTracksInWindow');

        $def = new RetourEnArriereDefinition($navidrome);
        $this->assertSame([], $def->build($this->ctx('2026-06-27')));
    }

    public function testClampsYearsToAvailableHistory(): void
    {
        // First scrobble ~2.5 years before "now" → only 2 full years back,
        // so exactly 2 windows are queried even though maxYears=10.
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->method('getScrobbleBounds')->willReturn([
            'first' => new \DateTimeImmutable('2024-01-01'),
            'last' => new \DateTimeImmutable('2026-06-27'),
        ]);
        $navidrome->expects($this->exactly(2))
            ->method('topTracksInWindow')
            ->willReturn(['mf-1']);

        $def = new RetourEnArriereDefinition($navidrome, maxYears: 10, windowDays: 15, perYear: 10);
        $ids = $def->build($this->ctx('2026-06-27'));

        // Same id across both windows → deduped to one.
        $this->assertSame(['mf-1'], $ids);
    }

    public function testWindowsEndAtEachAnniversaryAndSpanWindowDays(): void
    {
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->method('getScrobbleBounds')->willReturn([
            'first' => new \DateTimeImmutable('2010-01-01'),
            'last' => new \DateTimeImmutable('2026-06-27'),
        ]);

        $captured = [];
        $navidrome->method('topTracksInWindow')->willReturnCallback(
            function (\DateTimeInterface $from, \DateTimeInterface $to, int $limit) use (&$captured): array {
                $captured[] = [$from->format('Y-m-d'), $to->format('Y-m-d'), $limit];

                return [];
            },
        );

        $def = new RetourEnArriereDefinition($navidrome, maxYears: 2, windowDays: 15, perYear: 10);
        $def->build($this->ctx('2026-06-27'));

        // Year 1: [2025-06-12, 2025-06-27]; Year 2: [2024-06-12, 2024-06-27].
        $this->assertSame(['2025-06-12', '2025-06-27', 10], $captured[0]);
        $this->assertSame(['2024-06-12', '2024-06-27', 10], $captured[1]);
    }

    public function testUnionDedupesAcrossYearsMostRecentFirst(): void
    {
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->method('getScrobbleBounds')->willReturn([
            'first' => new \DateTimeImmutable('2010-01-01'),
            'last' => new \DateTimeImmutable('2026-06-27'),
        ]);
        // Year 1 (most recent) → [a, b]; Year 2 (older) → [b, c].
        $navidrome->method('topTracksInWindow')->willReturnOnConsecutiveCalls(
            ['a', 'b'],
            ['b', 'c'],
        );

        $def = new RetourEnArriereDefinition($navidrome, maxYears: 2, windowDays: 30, perYear: 10);
        $ids = $def->build($this->ctx('2026-06-27'));

        // No shuffle: deterministic, most-recent year first, dedup keeps the
        // earliest (most recent) occurrence of a repeated track.
        $this->assertSame(['a', 'b', 'c'], $ids);
    }

    private function ctx(string $now): PlaylistContext
    {
        return new PlaylistContext(new \DateTimeImmutable($now));
    }
}
