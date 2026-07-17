<?php

namespace App\Tests\Playlist;

use App\Navidrome\NavidromeRepository;
use App\Playlist\Definition\CoupsDeCoeurDefinition;
use App\Playlist\Definition\DecouvertesRecentesDefinition;
use App\Playlist\Definition\FidelesCompagnonsDefinition;
use App\Playlist\Definition\HappyBirthdayDefinition;
use App\Playlist\Definition\HitParadeDefinition;
use App\Playlist\Definition\KickstartDefinition;
use App\Playlist\Definition\PepitesOublieesDefinition;
use App\Playlist\Definition\PepitesRedecouvertesDefinition;
use App\Playlist\Definition\TresVieillesPepitesDefinition;
use App\Playlist\Definition\TopAllTimeDefinition;
use App\Playlist\Definition\TopDeLanneeDefinition;
use App\Playlist\Definition\TopDuMoisDefinition;
use App\Playlist\PlaylistContext;
use PHPUnit\Framework\TestCase;

/**
 * Thin coverage of the four reuse-heavy definitions: each must call the
 * right existing repo method with the right args and return media_file ids.
 * Shuffle makes order non-deterministic, so id-set assertions sort first.
 */
class DefinitionsTest extends TestCase
{
    public function testTopDuMoisQueriesPreviousCalendarMonth(): void
    {
        $navidrome = $this->createMock(NavidromeRepository::class);
        // now = 2026-01-15 → previous month = December 2025.
        $navidrome->expects($this->once())
            ->method('getTopTracksWithDates')
            ->with(2025, 12, null, 50)
            ->willReturn([['id' => 'mf-1'], ['id' => 'mf-2']]);

        $ids = (new TopDuMoisDefinition($navidrome, 50))->build($this->ctx('2026-01-15'));

        sort($ids);
        $this->assertSame(['mf-1', 'mf-2'], $ids);
    }

    public function testTopAllTimeQueriesWithoutDateFilter(): void
    {
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->expects($this->once())
            ->method('getTopTracksWithDates')
            ->with(null, null, null, 25)
            ->willReturn([['id' => 'mf-9']]);

        $this->assertSame(['mf-9'], (new TopAllTimeDefinition($navidrome, 25))->build($this->ctx('2026-06-27')));
    }

    public function testPepitesOublieesUsesCutoffFromSilenceMonths(): void
    {
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->expects($this->once())
            ->method('getSongsLovedAndForgotten')
            ->with(
                5,
                $this->callback(static fn (\DateTimeInterface $d): bool => $d->format('Y-m-d') === '2025-06-27'),
                50,
            )
            ->willReturn(['mf-old']);

        $def = new PepitesOublieesDefinition($navidrome, minPlays: 5, silenceMonths: 12, limit: 50);
        $this->assertSame(['mf-old'], $def->build($this->ctx('2026-06-27')));
    }

    public function testTresVieillesPepitesUsesLongerSilenceCutoff(): void
    {
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->expects($this->once())
            ->method('getSongsLovedAndForgotten')
            ->with(
                5,
                // 60 months before 2026-06-27 → 2021-06-27.
                $this->callback(static fn (\DateTimeInterface $d): bool => $d->format('Y-m-d') === '2021-06-27'),
                50,
            )
            ->willReturn(['mf-ancient']);

        $def = new TresVieillesPepitesDefinition($navidrome, minPlays: 5, silenceMonths: 60, limit: 50);

        $this->assertSame('tres-vieilles-pepites', $def->getSlug());
        $this->assertSame(['mf-ancient'], $def->build($this->ctx('2026-06-27')));
    }

    public function testPepitesRedecouvertesUsesThreeWindows(): void
    {
        $navidrome = $this->createMock(NavidromeRepository::class);
        // now = 2026-06-27, recent=1mo, silence=12mo, window=24mo →
        //   recentSince  = 2026-05-27
        //   silenceStart = 2025-05-27 (now - 13 months)
        //   windowStart  = 2024-06-27 (now - 24 months)
        $navidrome->expects($this->once())
            ->method('findRediscoveredGems')
            ->with(
                $this->callback(static fn (\DateTimeInterface $d): bool => $d->format('Y-m-d') === '2024-06-27'),
                $this->callback(static fn (\DateTimeInterface $d): bool => $d->format('Y-m-d') === '2025-05-27'),
                $this->callback(static fn (\DateTimeInterface $d): bool => $d->format('Y-m-d') === '2026-05-27'),
                50,
            )
            ->willReturn(['mf-x', 'mf-y']);

        $def = new PepitesRedecouvertesDefinition($navidrome, windowMonths: 24, silenceMonths: 12, recentMonths: 1, limit: 50);

        $this->assertSame('pepites-redecouvertes', $def->getSlug());
        $ids = $def->build($this->ctx('2026-06-27'));
        sort($ids); // shuffled → assert the set.
        $this->assertSame(['mf-x', 'mf-y'], $ids);
    }

    public function testCoupsDeCoeurCollectsStarredAndCapsAtLimit(): void
    {
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->method('iterateStarredMediaFiles')->willReturnCallback(function (): \Generator {
            yield ['id' => 'a'];
            yield ['id' => 'b'];
            yield ['id' => 'c'];
        });

        $ids = (new CoupsDeCoeurDefinition($navidrome, 2))->build($this->ctx('2026-06-27'));

        $this->assertCount(2, $ids);
        // Both kept ids must come from the starred set.
        foreach ($ids as $id) {
            $this->assertContains($id, ['a', 'b', 'c']);
        }
    }

    public function testKickstartReturnsRepoOrderWithoutShuffle(): void
    {
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->expects($this->once())
            ->method('getDailyKickstartTracks')
            ->with(50)
            ->willReturn(['mf-a', 'mf-b', 'mf-c']);

        // No shuffle: « le top » keeps the frequency-ranked order as-is.
        $ids = (new KickstartDefinition($navidrome, 50))->build($this->ctx('2026-06-27'));
        $this->assertSame(['mf-a', 'mf-b', 'mf-c'], $ids);
    }

    public function testHappyBirthdayQueriesConfiguredDayAndShuffles(): void
    {
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->expects($this->once())
            ->method('getTopTracksOnDayOfYear')
            ->with(5, 22, 50)
            ->willReturn(['mf-a', 'mf-b', 'mf-c']);

        $ids = (new HappyBirthdayDefinition($navidrome, month: 5, day: 22, limit: 50))->build($this->ctx('2026-06-27'));

        sort($ids); // shuffled → assert the set.
        $this->assertSame(['mf-a', 'mf-b', 'mf-c'], $ids);
    }

    public function testHitParadeQueriesEachWeekRecentFirstAndDedupes(): void
    {
        $navidrome = $this->createMock(NavidromeRepository::class);

        $captured = [];
        $navidrome->method('topTracksInWindow')->willReturnCallback(
            function (\DateTimeInterface $from, \DateTimeInterface $to, int $limit) use (&$captured): array {
                $captured[] = [$from->format('Y-m-d'), $to->format('Y-m-d'), $limit];

                // Week 1 → [a, b]; week 2 → [b, c]. Union dedup = [a, b, c].
                return count($captured) === 1 ? ['a', 'b'] : ['b', 'c'];
            },
        );

        $ids = (new HitParadeDefinition($navidrome, weeks: 2, perWeek: 3))->build($this->ctx('2026-06-27'));

        // Two 7-day windows, most recent first, top-3 each.
        $this->assertSame(['2026-06-20', '2026-06-27', 3], $captured[0]);
        $this->assertSame(['2026-06-13', '2026-06-20', 3], $captured[1]);
        // No shuffle: deterministic, recent week first, deduped.
        $this->assertSame(['a', 'b', 'c'], $ids);
    }

    public function testTopDeLanneeQueriesPreviousCalendarYear(): void
    {
        $navidrome = $this->createMock(NavidromeRepository::class);
        // now = 2026-06-27 → previous full year = 2025.
        $navidrome->expects($this->once())
            ->method('getTopTracksWithDates')
            ->with(2025, null, null, 50)
            ->willReturn([['id' => 'mf-1'], ['id' => 'mf-2']]);

        $ids = (new TopDeLanneeDefinition($navidrome, 50))->build($this->ctx('2026-06-27'));

        sort($ids); // shuffled → assert the set.
        $this->assertSame(['mf-1', 'mf-2'], $ids);
    }

    public function testDecouvertesRecentesUsesCutoffFromSinceDays(): void
    {
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->expects($this->once())
            ->method('getRecentlyDiscoveredTracks')
            ->with(
                $this->callback(static fn (\DateTimeInterface $d): bool => $d->format('Y-m-d') === '2026-05-28'),
                50,
            )
            ->willReturn(['mf-new']);

        $def = new DecouvertesRecentesDefinition($navidrome, sinceDays: 30, limit: 50);
        $this->assertSame(['mf-new'], $def->build($this->ctx('2026-06-27')));
    }

    public function testFidelesCompagnonsKeepsRepoOrder(): void
    {
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->expects($this->once())
            ->method('getMostConsistentTracks')
            ->with(50)
            ->willReturn(['mf-a', 'mf-b']);

        // Ranked by regularity → no shuffle.
        $this->assertSame(['mf-a', 'mf-b'], (new FidelesCompagnonsDefinition($navidrome, 50))->build($this->ctx('2026-06-27')));
    }

    public function testSlugsAndNamesAreStable(): void
    {
        $navidrome = $this->createMock(NavidromeRepository::class);
        $this->assertSame('top-du-mois', (new TopDuMoisDefinition($navidrome))->getSlug());
        $this->assertSame('top-all-time', (new TopAllTimeDefinition($navidrome))->getSlug());
        $this->assertSame('pepites-oubliees', (new PepitesOublieesDefinition($navidrome))->getSlug());
        $this->assertSame('coups-de-coeur', (new CoupsDeCoeurDefinition($navidrome))->getSlug());
        $this->assertSame('kickstart', (new KickstartDefinition($navidrome))->getSlug());
        $this->assertSame('happy-birthday', (new HappyBirthdayDefinition($navidrome))->getSlug());
        $this->assertSame('hit-parade', (new HitParadeDefinition($navidrome))->getSlug());
        $this->assertSame('top-de-lannee', (new TopDeLanneeDefinition($navidrome))->getSlug());
        $this->assertSame('decouvertes-recentes', (new DecouvertesRecentesDefinition($navidrome))->getSlug());
        $this->assertSame('fideles-compagnons', (new FidelesCompagnonsDefinition($navidrome))->getSlug());
    }

    private function ctx(string $now): PlaylistContext
    {
        return new PlaylistContext(new \DateTimeImmutable($now));
    }
}
