<?php

namespace App\Tests\Playlist;

use App\AudioMuse\AudioMuseClient;
use App\AudioMuse\AudioMuseException;
use App\Navidrome\NavidromeRepository;
use App\Playlist\Definition\CalmesPeuEcouteesDefinition;
use App\Playlist\Definition\CourseDefinition;
use App\Playlist\Definition\EmpreinteSonoreDefinition;
use App\Playlist\Definition\EnergiquesOublieesDefinition;
use App\Playlist\Definition\KickstartEnergiqueDefinition;
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

    public function testKickstartEnergiqueKeepsKickstartOrderAndFiltersEnergyAndTempo(): void
    {
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->method('filterMissingMediaFileIds')->willReturn([]);
        $features = $this->createMock(AudioFeatureRepository::class);
        $features->method('energyPercentile')->with(60)->willReturn(0.6);
        // Bunched scores (#271): the library's 75th percentile is 0.64, not 0.5.
        $features->method('moodPercentile')->with('danceable', 75)->willReturn(0.64);
        $features->method('featuresByIds')->willReturn([
            'fast-loud' => ['tempo' => 128.0, 'energy' => 0.8, 'danceable' => 0.2],
            'soft' => ['tempo' => 130.0, 'energy' => 0.3, 'danceable' => 0.9],
            'half-tempo-dance' => ['tempo' => 64.0, 'energy' => 0.7, 'danceable' => 0.7],
            'slow-average-dance' => ['tempo' => 70.0, 'energy' => 0.9, 'danceable' => 0.61],
            'slow-loud' => ['tempo' => 70.0, 'energy' => 0.9, 'danceable' => 0.1],
            'loud-2' => ['tempo' => 110.0, 'energy' => 0.6, 'danceable' => null],
        ]);
        $navidrome->expects($this->once())->method('getDailyKickstartTracks')->with(500)
            ->willReturn(['fast-loud', 'soft', 'half-tempo-dance', 'slow-average-dance', 'slow-loud', 'unanalysed', 'loud-2']);

        $def = new KickstartEnergiqueDefinition($navidrome, $features, energyPercentile: 60, minBpm: 110, danceablePercentile: 75);

        $this->assertSame('kickstart-energique', $def->getSlug());
        // Kickstart order kept. Dropped: soft (energy), slow-average-dance (0.61 ≥ 0.5 but
        // < p75: the old absolute threshold let it in), slow-loud (tempo, not danceable), unanalysed.
        $this->assertSame(['fast-loud', 'half-tempo-dance', 'loud-2'], $def->build($this->ctx()));
    }

    public function testKickstartEnergiqueIsEmptyBeforeAnyImport(): void
    {
        $features = $this->createMock(AudioFeatureRepository::class);
        $features->method('energyPercentile')->willReturn(null);
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->expects($this->never())->method('getDailyKickstartTracks');

        $this->assertSame([], (new KickstartEnergiqueDefinition($navidrome, $features))->build($this->ctx()));
    }

    public function testEmpreinteSonoreKeepsAudioMuseOrderAndDropsMissing(): void
    {
        $audioMuse = $this->createMock(AudioMuseClient::class);
        $audioMuse->method('isConfigured')->willReturn(true);
        $audioMuse->expects($this->once())->method('sonicFingerprint')->with(3)->willReturn(['a', 'gone', 'b', 'a', 'c', 'd']);
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->method('filterMissingMediaFileIds')->willReturn(['gone']);

        $def = new EmpreinteSonoreDefinition($navidrome, $audioMuse, limit: 3);

        $this->assertSame('empreinte-sonore', $def->getSlug());
        $this->assertSame(['a', 'b', 'c'], $def->build($this->ctx()));
    }

    public function testEmpreinteSonoreInactiveWithoutAudioMuse(): void
    {
        $audioMuse = $this->createMock(AudioMuseClient::class);
        $audioMuse->method('isConfigured')->willReturn(false);
        $audioMuse->expects($this->never())->method('sonicFingerprint');

        $this->assertSame([], (new EmpreinteSonoreDefinition($this->createMock(NavidromeRepository::class), $audioMuse))->build($this->ctx()));
    }

    public function testEmpreinteSonoreEmptyFingerprintIsAnError(): void
    {
        $audioMuse = $this->createMock(AudioMuseClient::class);
        $audioMuse->method('isConfigured')->willReturn(true);
        $audioMuse->method('sonicFingerprint')->willReturn([]);
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->expects($this->never())->method('filterMissingMediaFileIds');

        $this->expectException(AudioMuseException::class);
        $this->expectExceptionMessage('empreinte vide');
        (new EmpreinteSonoreDefinition($navidrome, $audioMuse))->build($this->ctx());
    }
}
