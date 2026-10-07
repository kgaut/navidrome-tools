<?php

namespace App\Tests\Playlist;

use App\AudioMuse\AudioMuseClient;
use App\Entity\DescribedPlaylist;
use App\Navidrome\NavidromeRepository;
use App\Playlist\Definition\DescribedPlaylistDefinition;
use App\Playlist\DescribedPlaylistProvider;
use App\Playlist\PlaylistContext;
use App\Playlist\PlaylistDefinitionInterface;
use App\Repository\DescribedPlaylistRepository;
use PHPUnit\Framework\TestCase;

class DescribedPlaylistDefinitionTest extends TestCase
{
    private function playlist(int $id, string $query, int $count, ?int $maxPlays = null): DescribedPlaylist
    {
        $playlist = new DescribedPlaylist('  Piano sous la pluie ', $query, $count, $maxPlays);
        (new \ReflectionProperty(DescribedPlaylist::class, 'id'))->setValue($playlist, $id);

        return $playlist;
    }

    private function ctx(): PlaylistContext
    {
        return new PlaylistContext(new \DateTimeImmutable('2026-10-07 12:00:00'));
    }

    public function testSlugNameAndDescriptionComeFromTheRow(): void
    {
        $def = new DescribedPlaylistDefinition(
            $this->playlist(7, 'piano calme, pluie', 30, 3),
            $this->createMock(AudioMuseClient::class),
            $this->createMock(NavidromeRepository::class),
        );

        $this->assertSame('decrite-7', $def->getSlug());
        $this->assertSame('Piano sous la pluie', $def->getName());
        $this->assertStringContainsString('« piano calme, pluie »', $def->getDescription());
        $this->assertStringContainsString('au plus 3 fois', $def->getDescription());
    }

    public function testReturnsEmptyWhenAudioMuseNotConfigured(): void
    {
        $audioMuse = $this->createMock(AudioMuseClient::class);
        $audioMuse->method('isConfigured')->willReturn(false);
        $audioMuse->expects($this->never())->method('textSearch');

        $def = new DescribedPlaylistDefinition($this->playlist(1, 'x', 10), $audioMuse, $this->createMock(NavidromeRepository::class));

        $this->assertSame([], $def->build($this->ctx()));
    }

    public function testKeepsAudioMuseOrderDropsMissingAndCaps(): void
    {
        $audioMuse = $this->createMock(AudioMuseClient::class);
        $audioMuse->method('isConfigured')->willReturn(true);
        $audioMuse->expects($this->once())->method('textSearch')->with('piano calme', 3)->willReturn([
            ['item_id' => 'b', 'similarity' => 0.4],
            ['item_id' => 'gone', 'similarity' => 0.39],
            ['item_id' => 'a', 'similarity' => 0.3],
            ['item_id' => 'b', 'similarity' => 0.3], // duplicate
            ['item_id' => 'c', 'similarity' => 0.2],
        ]);
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->expects($this->never())->method('getPlayCountsByMediaFileId');
        $navidrome->method('filterMissingMediaFileIds')->willReturn(['gone']);

        $def = new DescribedPlaylistDefinition($this->playlist(1, 'piano calme', 3), $audioMuse, $navidrome);

        $this->assertSame(['b', 'a', 'c'], $def->build($this->ctx()));
    }

    public function testMaxPlaysWidensThePoolAndFiltersFamiliarTracks(): void
    {
        $audioMuse = $this->createMock(AudioMuseClient::class);
        $audioMuse->method('isConfigured')->willReturn(true);
        $audioMuse->expects($this->once())->method('textSearch')->with('rock', 10)->willReturn([
            ['item_id' => 'heard', 'similarity' => 0.5],
            ['item_id' => 'never', 'similarity' => 0.4],
            ['item_id' => 'once', 'similarity' => 0.3],
        ]);
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->method('getPlayCountsByMediaFileId')->willReturn(['heard' => 12, 'once' => 1]);
        $navidrome->method('filterMissingMediaFileIds')->willReturn([]);

        $def = new DescribedPlaylistDefinition($this->playlist(1, 'rock', 2, 1), $audioMuse, $navidrome);

        $this->assertSame(['never', 'once'], $def->build($this->ctx()));
    }

    public function testProviderYieldsOneDefinitionPerRow(): void
    {
        $repository = $this->createMock(DescribedPlaylistRepository::class);
        $repository->method('findAllOrdered')->willReturn([$this->playlist(1, 'a', 5), $this->playlist(2, 'b', 5)]);

        $provider = new DescribedPlaylistProvider(
            $repository,
            $this->createMock(AudioMuseClient::class),
            $this->createMock(NavidromeRepository::class),
        );
        $slugs = array_map(
            static fn (PlaylistDefinitionInterface $d): string => $d->getSlug(),
            iterator_to_array($provider->getDefinitions(), false),
        );

        $this->assertSame(['decrite-1', 'decrite-2'], $slugs);
    }
}
