<?php

namespace App\Tests\Service;

use App\Service\StreakStats;
use PHPUnit\Framework\TestCase;

class StreakStatsTest extends TestCase
{
    public function testEmptyInputReturnsZeroes(): void
    {
        $r = StreakStats::compute([], new \DateTimeImmutable('2026-01-15'));

        $this->assertSame(0, $r['longest']);
        $this->assertNull($r['longest_started_at']);
        $this->assertNull($r['longest_ended_at']);
        $this->assertSame(0, $r['current']);
        $this->assertNull($r['current_started_at']);
    }

    public function testLongestRunPicksMaxStretch(): void
    {
        $days = ['2026-01-01', '2026-01-02', '2026-01-03', '2026-01-10', '2026-01-11'];
        $r = StreakStats::compute($days, new \DateTimeImmutable('2026-02-01'));

        $this->assertSame(3, $r['longest']);
        $this->assertSame('2026-01-01', $r['longest_started_at']);
        $this->assertSame('2026-01-03', $r['longest_ended_at']);
        $this->assertSame(0, $r['current'], 'no play near today → current is 0');
    }

    public function testLongestRunTieBreaksOnEarliest(): void
    {
        // Two runs of length 3 — keep the earliest interval.
        $days = [
            '2026-01-01', '2026-01-02', '2026-01-03',
            '2026-02-10', '2026-02-11', '2026-02-12',
        ];
        $r = StreakStats::compute($days, new \DateTimeImmutable('2026-03-01'));

        $this->assertSame(3, $r['longest']);
        $this->assertSame('2026-01-01', $r['longest_started_at']);
        $this->assertSame('2026-01-03', $r['longest_ended_at']);
    }

    public function testSingleDayHasMatchingLongestBounds(): void
    {
        $r = StreakStats::compute(['2026-04-12'], new \DateTimeImmutable('2026-05-01'));

        $this->assertSame(1, $r['longest']);
        $this->assertSame('2026-04-12', $r['longest_started_at']);
        $this->assertSame('2026-04-12', $r['longest_ended_at']);
    }

    public function testCurrentStreakEndsToday(): void
    {
        $today = new \DateTimeImmutable('2026-05-24');
        $days = ['2026-05-20', '2026-05-21', '2026-05-22', '2026-05-23', '2026-05-24'];
        $r = StreakStats::compute($days, $today);

        $this->assertSame(5, $r['longest']);
        $this->assertSame(5, $r['current']);
        $this->assertSame('2026-05-20', $r['current_started_at']);
        $this->assertSame('2026-05-24', $r['current_ended_at']);
    }

    public function testCurrentStreakEndsYesterdayWhenTodayMissing(): void
    {
        // Habit-tracker convention: opening at 9am before the first play of
        // the day shouldn't reset the streak. Yesterday's run counts.
        $today = new \DateTimeImmutable('2026-05-24');
        $days = ['2026-05-22', '2026-05-23'];
        $r = StreakStats::compute($days, $today);

        $this->assertSame(2, $r['longest']);
        $this->assertSame(2, $r['current']);
        $this->assertSame('2026-05-23', $r['current_ended_at']);
    }

    public function testCurrentStreakIsZeroWhenGapMoreThanOneDay(): void
    {
        $today = new \DateTimeImmutable('2026-05-24');
        $days = ['2026-05-20', '2026-05-21'];
        $r = StreakStats::compute($days, $today);

        $this->assertSame(2, $r['longest']);
        $this->assertSame(0, $r['current']);
    }

    public function testDuplicateDaysCollapse(): void
    {
        $today = new \DateTimeImmutable('2026-05-24');
        $days = ['2026-05-23', '2026-05-23', '2026-05-24', '2026-05-24'];
        $r = StreakStats::compute($days, $today);

        $this->assertSame(2, $r['longest']);
        $this->assertSame(2, $r['current']);
    }
}
