<?php

namespace App\Tests\Playlist;

use App\AudioMuse\AudioMuseClient;
use App\AudioMuse\AudioMuseException;
use App\Navidrome\NavidromeRepository;
use App\Playlist\Definition\MixSemaineDefinition;
use App\Playlist\PlaylistContext;
use PHPUnit\Framework\TestCase;

class MixSemaineDefinitionTest extends TestCase
{
    private function ctx(): PlaylistContext
    {
        return new PlaylistContext(new \DateTimeImmutable('2026-06-29 12:00:00'), 50);
    }

    public function testReturnsEmptyWhenAudioMuseNotConfigured(): void
    {
        $audioMuse = $this->createMock(AudioMuseClient::class);
        $audioMuse->method('isConfigured')->willReturn(false);
        $audioMuse->expects($this->never())->method('similarTracks');

        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->expects($this->never())->method('topTracksInWindow');

        $def = new MixSemaineDefinition($navidrome, $audioMuse);

        $this->assertSame('mix-semaine', $def->getSlug());
        $this->assertSame([], $def->build($this->ctx()));
    }

    public function testMixesSeedsWithUnfamiliarSimilarTracks(): void
    {
        $audioMuse = $this->createMock(AudioMuseClient::class);
        $audioMuse->method('isConfigured')->willReturn(true);
        $audioMuse->method('similarTracks')->willReturnCallback(
            static function (string $itemId): array {
                if ($itemId === 's1') {
                    return [
                        ['item_id' => 'd1', 'distance' => 0.1],
                        ['item_id' => 'd2', 'distance' => 0.2],
                        ['item_id' => 's2', 'distance' => 0.05], // a seed → excluded
                    ];
                }

                return [
                    ['item_id' => 'd1', 'distance' => 0.3], // also surfaced by s1
                    ['item_id' => 'd3', 'distance' => 0.4], // too familiar (10 plays)
                    ['item_id' => 'fam', 'distance' => 0.5], // too familiar (6 plays)
                ];
            },
        );

        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->method('topTracksInWindow')->willReturn(['s1', 's2']);
        $navidrome->method('getPlayCountsByMediaFileId')->willReturnCallback(
            static fn (array $ids): array => array_intersect_key(
                ['d1' => 0, 'd2' => 3, 'd3' => 10, 'fam' => 6],
                array_fill_keys($ids, true),
            ),
        );
        $navidrome->method('filterMissingMediaFileIds')->willReturn([]);

        $def = new MixSemaineDefinition($navidrome, $audioMuse, limit: 4);
        $ids = $def->build($this->ctx());

        sort($ids);
        // Seeds s1,s2 + unfamiliar discoveries d1,d2. d3 (10 plays) and
        // fam (6 plays) excluded by the ≤5 familiarity filter.
        $this->assertSame(['d1', 'd2', 's1', 's2'], $ids);
    }

    public function testASeedThatErrorsDoesNotAbortTheRest(): void
    {
        $audioMuse = $this->createMock(AudioMuseClient::class);
        $audioMuse->method('isConfigured')->willReturn(true);
        $audioMuse->method('similarTracks')->willReturnCallback(
            static function (string $itemId): array {
                if ($itemId === 'broken') {
                    throw new AudioMuseException('not analysed');
                }

                return [['item_id' => 'd1', 'distance' => 0.1]];
            },
        );

        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->method('topTracksInWindow')->willReturn(['broken', 'ok']);
        $navidrome->method('getPlayCountsByMediaFileId')->willReturn(['d1' => 0]);
        $navidrome->method('filterMissingMediaFileIds')->willReturn([]);

        $def = new MixSemaineDefinition($navidrome, $audioMuse, limit: 10);
        $ids = $def->build($this->ctx());

        $this->assertContains('d1', $ids); // surfaced by the surviving seed
    }

    public function testReturnsEmptyWhenNoRecentSeeds(): void
    {
        $audioMuse = $this->createMock(AudioMuseClient::class);
        $audioMuse->method('isConfigured')->willReturn(true);
        $audioMuse->expects($this->never())->method('similarTracks');

        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->method('topTracksInWindow')->willReturn([]);

        $def = new MixSemaineDefinition($navidrome, $audioMuse);

        $this->assertSame([], $def->build($this->ctx()));
    }
}
