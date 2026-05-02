<?php

namespace App\Tests\Service;

use App\Navidrome\NavidromeRepository;
use App\Service\PlaylistStatsService;
use PHPUnit\Framework\TestCase;

class PlaylistStatsServiceTest extends TestCase
{
    public function testComputeAggregatesArtistsYearsAndStarred(): void
    {
        $navidrome = $this->createNavidromeStub(false);
        $svc = new PlaylistStatsService($navidrome);

        $stats = $svc->compute([
            $this->track('mf-1', 'Halo', 'Beyoncé', 'I Am', 200, 12, 2008, '2025-01-01T00:00:00Z'),
            $this->track('mf-2', 'Single Ladies', 'Beyoncé', 'I Am', 220, 0, 2008, null),
            $this->track('mf-3', 'Smile', 'Lily Allen', 'Alright Still', 160, 5, 2006, null),
        ]);

        $this->assertSame(3, $stats['trackCount']);
        $this->assertSame(580, $stats['totalDuration']);
        $this->assertSame(1, $stats['starredCount']);
        $this->assertSame(1, $stats['neverPlayedCount']);
        $this->assertEqualsWithDelta(1 / 3, $stats['neverPlayedRatio'], 0.0001);

        $this->assertSame('Beyoncé', $stats['topArtists'][0]['artist']);
        $this->assertSame(2, $stats['topArtists'][0]['count']);

        $this->assertSame([2006 => 1, 2008 => 2], $stats['yearDistribution']);
        $this->assertSame(0, $stats['missingYearCount']);

        $this->assertSame('I Am', $stats['topAlbums'][0]['album']);
        $this->assertSame(2, $stats['topAlbums'][0]['count']);
    }

    public function testComputeBacksOffWhenNavidromeOfflineForMissingYears(): void
    {
        $navidrome = $this->createNavidromeStub(false);
        $svc = new PlaylistStatsService($navidrome);

        $stats = $svc->compute([
            $this->track('mf-1', 'A', 'X', 'Y', 100, 0, null, null),
            $this->track('mf-2', 'B', 'X', 'Y', 100, 0, 2010, null),
        ]);

        $this->assertSame([2010 => 1], $stats['yearDistribution']);
        $this->assertSame(1, $stats['missingYearCount']);
    }

    public function testComputeBacksFillsMissingYearsFromMediaFile(): void
    {
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->method('isAvailable')->willReturn(true);
        $navidrome->method('getMediaFileMetadata')
            ->with(['mf-1'])
            ->willReturn([['id' => 'mf-1', 'artist' => 'X', 'album' => 'Y', 'year' => 1995, 'duration' => 100]]);

        $svc = new PlaylistStatsService($navidrome);
        $stats = $svc->compute([
            $this->track('mf-1', 'A', 'X', 'Y', 100, 0, null, null),
            $this->track('mf-2', 'B', 'X', 'Y', 100, 0, 2010, null),
        ]);

        $this->assertSame([1995 => 1, 2010 => 1], $stats['yearDistribution']);
        $this->assertSame(0, $stats['missingYearCount']);
    }

    public function testComputeEmptyPlaylistReturnsZeroes(): void
    {
        $navidrome = $this->createNavidromeStub(false);
        $svc = new PlaylistStatsService($navidrome);

        $stats = $svc->compute([]);

        $this->assertSame(0, $stats['trackCount']);
        $this->assertSame(0, $stats['totalDuration']);
        $this->assertSame(0.0, $stats['neverPlayedRatio']);
        $this->assertSame([], $stats['topArtists']);
        $this->assertSame([], $stats['topAlbums']);
        $this->assertSame([], $stats['yearDistribution']);
    }

    /**
     * @return array{
     *     id: string, title: string, artist: string, album: string,
     *     duration: int, playCount: int, year: ?int, starred: ?string, path: string
     * }
     */
    private function track(
        string $id,
        string $title,
        string $artist,
        string $album,
        int $duration,
        int $playCount,
        ?int $year,
        ?string $starred,
    ): array {
        return [
            'id' => $id,
            'title' => $title,
            'artist' => $artist,
            'album' => $album,
            'duration' => $duration,
            'playCount' => $playCount,
            'year' => $year,
            'starred' => $starred,
            'path' => '',
        ];
    }

    private function createNavidromeStub(bool $available): NavidromeRepository
    {
        $stub = $this->createMock(NavidromeRepository::class);
        $stub->method('isAvailable')->willReturn($available);

        return $stub;
    }
}
