<?php

namespace App\Tests\AudioMuse;

use App\AudioMuse\AudioFeatureSync;
use App\AudioMuse\AudioMuseClient;
use App\AudioMuse\AudioMuseException;
use App\AudioMuse\TrackFeatures;
use App\Repository\AudioFeatureRepository;
use PHPUnit\Framework\TestCase;

class AudioFeatureSyncTest extends TestCase
{
    private static function track(string $id, string $fp): TrackFeatures
    {
        return new TrackFeatures($id, $fp, 120.0, null, null, 0.5, [], []);
    }

    public function testFetchesOnlyNewAndChangedTracksAndRemovesGoneOnes(): void
    {
        $client = $this->createMock(AudioMuseClient::class);
        $client->method('isConfigured')->willReturn(true);
        $client->method('syncManifest')->willReturnCallback(static fn (int $page): array => match ($page) {
            1 => ['tracks' => ['same' => 'fp1', 'changed' => 'fp-new'], 'has_more' => true],
            default => ['tracks' => ['new' => 'fp1'], 'has_more' => false],
        });
        $client->expects($this->once())->method('syncTracks')
            ->with(['changed', 'new'])
            ->willReturn([self::track('changed', 'fp-new'), self::track('new', 'fp1')]);

        $repo = $this->createMock(AudioFeatureRepository::class);
        $repo->method('fingerprints')->willReturn(['same' => 'fp1', 'changed' => 'fp-old', 'gone' => 'fp1']);
        $repo->expects($this->once())->method('upsert')
            ->with($this->callback(static fn (array $t): bool => count($t) === 2));
        $repo->expects($this->once())->method('deleteIds')->with(['gone'])->willReturn(1);

        $report = (new AudioFeatureSync($client, $repo))->run();

        $this->assertSame(3, $report->total);
        $this->assertSame(1, $report->added);
        $this->assertSame(1, $report->updated);
        $this->assertSame(1, $report->removed);
        $this->assertSame(1, $report->unchanged);
    }

    public function testFetchesInBatchesOf500(): void
    {
        $manifest = [];
        for ($i = 0; $i < 1200; $i++) {
            $manifest['mf-' . $i] = 'fp';
        }
        $client = $this->createMock(AudioMuseClient::class);
        $client->method('isConfigured')->willReturn(true);
        $client->method('syncManifest')->willReturn(['tracks' => $manifest, 'has_more' => false]);
        $client->expects($this->exactly(3))->method('syncTracks')
            ->with($this->callback(static fn (array $ids): bool => count($ids) <= 500))
            ->willReturn([]);

        $repo = $this->createMock(AudioFeatureRepository::class);
        $repo->method('fingerprints')->willReturn([]);
        $repo->expects($this->never())->method('deleteIds');

        $this->assertSame(1200, (new AudioFeatureSync($client, $repo))->run()->added);
    }

    public function testEmptyManifestNeverWipesLocalRows(): void
    {
        $client = $this->createMock(AudioMuseClient::class);
        $client->method('isConfigured')->willReturn(true);
        $client->method('syncManifest')->willReturn(['tracks' => [], 'has_more' => false]);

        $repo = $this->createMock(AudioFeatureRepository::class);
        $repo->method('fingerprints')->willReturn(['a' => 'fp']);
        $repo->expects($this->never())->method('deleteIds');

        $this->expectException(AudioMuseException::class);
        $this->expectExceptionMessage('empty manifest');
        (new AudioFeatureSync($client, $repo))->run();
    }
}
