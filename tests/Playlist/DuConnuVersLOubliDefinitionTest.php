<?php

namespace App\Tests\Playlist;

use App\AudioMuse\AudioMuseAuthException;
use App\AudioMuse\AudioMuseClient;
use App\AudioMuse\AudioMuseException;
use App\AudioMuse\AudioMuseNotFoundException;
use App\Navidrome\NavidromeRepository;
use App\Playlist\Definition\DuConnuVersLOubliDefinition;
use App\Playlist\PlaylistContext;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DuConnuVersLOubliDefinitionTest extends TestCase
{
    private function ctx(): PlaylistContext
    {
        return new PlaylistContext(new \DateTimeImmutable('2026-10-07 12:00:00'), 50);
    }

    private function audioMuse(): AudioMuseClient&MockObject
    {
        $audioMuse = $this->createMock(AudioMuseClient::class);
        $audioMuse->method('isConfigured')->willReturn(true);

        return $audioMuse;
    }

    public function testReturnsEmptyWhenAudioMuseNotConfigured(): void
    {
        $audioMuse = $this->createMock(AudioMuseClient::class);
        $audioMuse->method('isConfigured')->willReturn(false);
        $audioMuse->expects($this->never())->method('findPath');

        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->expects($this->never())->method('topTracksInWindow');

        $def = new DuConnuVersLOubliDefinition($navidrome, $audioMuse);

        $this->assertSame('du-connu-vers-l-oubli', $def->getSlug());
        $this->assertSame([], $def->build($this->ctx()));
    }

    public function testBuildsPathFromRecentTopToForgottenGemInPathOrder(): void
    {
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->expects($this->once())->method('topTracksInWindow')
            ->with(
                $this->callback(static fn (\DateTimeInterface $d): bool => $d->format('Y-m-d') === '2026-09-07'),
                $this->anything(),
                20,
            )
            ->willReturn(['top']);
        $navidrome->expects($this->once())->method('getSongsLovedAndForgotten')
            ->with(5, $this->callback(static fn (\DateTimeInterface $d): bool => $d->format('Y-m-d') === '2025-10-07'), 20)
            ->willReturn(['gem']);
        $navidrome->method('filterMissingMediaFileIds')->willReturn(['gone']);

        $audioMuse = $this->audioMuse();
        // AudioMuse may or may not include the endpoints; duplicates are dropped.
        $audioMuse->expects($this->once())->method('findPath')->with('top', 'gem', 25)
            ->willReturn(['a', 'b', 'a', 'gone', 'gem', 'c']);

        $ids = (new DuConnuVersLOubliDefinition($navidrome, $audioMuse))->build($this->ctx());

        $this->assertSame(['top', 'a', 'b', 'c', 'gem'], $ids);
    }

    public function testTriesAnotherPairWhenNoPathIsFound(): void
    {
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->method('topTracksInWindow')->willReturn(['t1', 't2']);
        $navidrome->method('getSongsLovedAndForgotten')->willReturn(['g1', 'g2']);
        $navidrome->method('filterMissingMediaFileIds')->willReturn([]);

        $calls = 0;
        $audioMuse = $this->audioMuse();
        $audioMuse->method('findPath')->willReturnCallback(
            static function () use (&$calls): array {
                if (++$calls === 1) {
                    throw new AudioMuseNotFoundException('HTTP 404');
                }

                return ['mid'];
            },
        );

        $ids = (new DuConnuVersLOubliDefinition($navidrome, $audioMuse))->build($this->ctx());

        $this->assertSame(2, $calls);
        $this->assertCount(3, $ids);
        $this->assertSame('mid', $ids[1]);
    }

    public function testFailsWhenNoPairYieldsAPath(): void
    {
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->method('topTracksInWindow')->willReturn(['t1', 't2', 't3', 't4']);
        $navidrome->method('getSongsLovedAndForgotten')->willReturn(['g1', 'g2', 'g3', 'g4']);

        $audioMuse = $this->audioMuse();
        $audioMuse->expects($this->exactly(3))->method('findPath')
            ->willThrowException(new AudioMuseNotFoundException('HTTP 404'));

        $this->expectException(AudioMuseException::class);
        $this->expectExceptionMessage('aucun chemin trouvé par AudioMuse en 3 essais');
        (new DuConnuVersLOubliDefinition($navidrome, $audioMuse))->build($this->ctx());
    }

    public function testAuthFailureIsNotRetried(): void
    {
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->method('topTracksInWindow')->willReturn(['t1', 't2']);
        $navidrome->method('getSongsLovedAndForgotten')->willReturn(['g1', 'g2']);

        $audioMuse = $this->audioMuse();
        $audioMuse->expects($this->once())->method('findPath')
            ->willThrowException(new AudioMuseAuthException('HTTP 401'));

        $this->expectException(AudioMuseAuthException::class);
        (new DuConnuVersLOubliDefinition($navidrome, $audioMuse))->build($this->ctx());
    }

    public function testReturnsEmptyWithoutStartOrEndCandidates(): void
    {
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->method('topTracksInWindow')->willReturn(['t1']);
        $navidrome->method('getSongsLovedAndForgotten')->willReturn(['t1']); // same track: not a path

        $audioMuse = $this->audioMuse();
        $audioMuse->expects($this->never())->method('findPath');

        $this->assertSame([], (new DuConnuVersLOubliDefinition($navidrome, $audioMuse))->build($this->ctx()));
    }
}
