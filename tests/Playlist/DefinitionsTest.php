<?php

namespace App\Tests\Playlist;

use App\Navidrome\NavidromeRepository;
use App\Playlist\Definition\CoupsDeCoeurDefinition;
use App\Playlist\Definition\PepitesOublieesDefinition;
use App\Playlist\Definition\TopAllTimeDefinition;
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

    public function testSlugsAndNamesAreStable(): void
    {
        $navidrome = $this->createMock(NavidromeRepository::class);
        $this->assertSame('top-du-mois', (new TopDuMoisDefinition($navidrome))->getSlug());
        $this->assertSame('top-all-time', (new TopAllTimeDefinition($navidrome))->getSlug());
        $this->assertSame('pepites-oubliees', (new PepitesOublieesDefinition($navidrome))->getSlug());
        $this->assertSame('coups-de-coeur', (new CoupsDeCoeurDefinition($navidrome))->getSlug());
    }

    private function ctx(string $now): PlaylistContext
    {
        return new PlaylistContext(new \DateTimeImmutable($now));
    }
}
