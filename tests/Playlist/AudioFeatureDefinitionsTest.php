<?php

namespace App\Tests\Playlist;

use App\Navidrome\NavidromeRepository;
use App\Playlist\Definition\CalmesPeuEcouteesDefinition;
use App\Playlist\Definition\CourseDefinition;
use App\Playlist\Definition\EnergiquesOublieesDefinition;
use App\Playlist\PlaylistContext;
use App\Repository\AudioFeatureRepository;
use PHPUnit\Framework\TestCase;

class AudioFeatureDefinitionsTest extends TestCase
{
    private function ctx(): PlaylistContext
    {
        return new PlaylistContext(new \DateTimeImmutable('2026-10-08 06:00:00'));
    }

    public function testCourseUsesBpmRangeAndHalfTempoAndKeepsListenedTracks(): void
    {
        $features = $this->createMock(AudioFeatureRepository::class);
        $features->expects($this->once())->method('idsInTempoRanges')
            ->with([[160.0, 180.0], [80.0, 90.0]])
            ->willReturn(['heard', 'never', 'often']);
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->method('getPlayCountsByMediaFileId')->willReturn(['heard' => 1, 'never' => 0, 'often' => 9]);
        $navidrome->method('filterMissingMediaFileIds')->willReturn([]);

        $def = new CourseDefinition($navidrome, $features, bpmMin: 160, bpmMax: 180, halfTempo: true, minPlays: 1);
        $ids = $def->build($this->ctx());
        sort($ids);

        $this->assertSame('course', $def->getSlug());
        $this->assertSame(['heard', 'often'], $ids);
    }

    public function testCourseWithoutHalfTempoQueriesOneRange(): void
    {
        $features = $this->createMock(AudioFeatureRepository::class);
        $features->expects($this->once())->method('idsInTempoRanges')->with([[150.0, 165.0]])->willReturn([]);

        $def = new CourseDefinition($this->createMock(NavidromeRepository::class), $features, bpmMin: 150, bpmMax: 165, halfTempo: false);

        $this->assertSame([], $def->build($this->ctx()));
    }

    public function testCalmesKeepsCalmestLittlePlayedTracks(): void
    {
        $features = $this->createMock(AudioFeatureRepository::class);
        $features->method('topByMood')->with('relaxed', 0.5, 3000)->willReturn(['c1', 'loud-fan', 'c2', 'c3', 'c4']);
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->method('getPlayCountsByMediaFileId')->willReturn(['c1' => 0, 'loud-fan' => 30, 'c2' => 2, 'c3' => 1, 'c4' => 0]);
        $navidrome->method('filterMissingMediaFileIds')->willReturn([]);

        // limit 1 → pool of the 3 calmest little-played (c1, c2, c3), then one of them.
        $ids = (new CalmesPeuEcouteesDefinition($navidrome, $features, maxPlays: 2, limit: 1))->build($this->ctx());

        $this->assertCount(1, $ids);
        $this->assertContains($ids[0], ['c1', 'c2', 'c3']);
    }

    public function testEnergiquesKeepsLoudestForgottenTracks(): void
    {
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->method('getSongsLovedAndForgotten')
            ->with(5, $this->callback(static fn (\DateTimeInterface $d): bool => $d->format('Y-m-d') === '2025-10-08'), 1000)
            ->willReturn(['soft', 'loud', 'mid', 'unanalysed']);
        $navidrome->method('filterMissingMediaFileIds')->willReturn([]);
        $features = $this->createMock(AudioFeatureRepository::class);
        $features->method('energyByIds')->willReturn(['soft' => 0.35, 'loud' => 0.9, 'mid' => 0.6]);

        // limit 1 → the 2 loudest (loud, mid) shuffled, one kept.
        $ids = (new EnergiquesOublieesDefinition($navidrome, $features, limit: 1))->build($this->ctx());

        $this->assertCount(1, $ids);
        $this->assertContains($ids[0], ['loud', 'mid']);
    }
}
