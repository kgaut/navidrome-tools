<?php

namespace App\Tests\Mcp;

use App\AudioMuse\AudioMuseAuthException;
use App\AudioMuse\AudioMuseClient;
use App\AudioMuse\AudioMuseNotFoundException;
use App\Entity\DescribedPlaylist;
use App\Exception\UserFacingException;
use App\Mcp\McpWriteGuard;
use App\Mcp\Tool\PlaylistTools;
use App\Navidrome\NavidromeRepository;
use App\Playlist\PlaylistGenerator;
use App\Service\DescribedPlaylistCreator;
use App\Subsonic\SubsonicClient;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PlaylistToolsTest extends TestCase
{
    private NavidromeRepository&MockObject $navidrome;
    private SubsonicClient&MockObject $subsonic;
    private PlaylistGenerator&MockObject $generator;
    private DescribedPlaylistCreator&MockObject $creator;
    private AudioMuseClient&MockObject $audioMuse;
    private McpWriteGuard&MockObject $guard;

    protected function setUp(): void
    {
        $this->navidrome = $this->createMock(NavidromeRepository::class);
        $this->subsonic = $this->createMock(SubsonicClient::class);
        $this->generator = $this->createMock(PlaylistGenerator::class);
        $this->generator->method('listDefinitions')->willReturn([
            ['slug' => 'top-mois', 'name' => 'Top du mois dernier', 'description' => '', 'enabled' => true],
        ]);
        $this->creator = $this->createMock(DescribedPlaylistCreator::class);
        $this->audioMuse = $this->createMock(AudioMuseClient::class);
        $this->guard = $this->createMock(McpWriteGuard::class);
    }

    public function testSearchClampsLimitAndReportsIt(): void
    {
        $this->navidrome->expects($this->once())->method('searchMediaFiles')
            ->with('radio', null, null, null, 100, 0)
            ->willReturn(['total' => 3, 'items' => []]);

        $result = $this->tools()->search(query: 'radio', limit: 5000);

        $this->assertSame(100, $result['limit']);
        $this->assertSame(3, $result['total']);
    }

    public function testCreateRefusesTheNameOfAGeneratedPlaylist(): void
    {
        $this->subsonic->expects($this->never())->method('createPlaylist');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('top-mois');
        $this->tools()->create('top du mois DERNIER', ['mf-1']);
    }

    public function testCreateRefusesUnknownIdsAndWritesNothing(): void
    {
        $this->navidrome->method('filterMissingMediaFileIds')->willReturn(['nope']);
        $this->subsonic->expects($this->never())->method('createPlaylist');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('nope');
        $this->tools()->create('Ma playlist', ['mf-1', 'nope']);
    }

    public function testCreateRefusesExistingPlaylistWithoutReplace(): void
    {
        $this->navidrome->method('filterMissingMediaFileIds')->willReturn([]);
        $this->subsonic->method('findPlaylistByName')->willReturn(self::playlist('pl-9'));
        $this->subsonic->expects($this->never())->method('replacePlaylist');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('replace = true');
        $this->tools()->create('Ma playlist', ['mf-1']);
    }

    public function testCreateReplacesWhenAsked(): void
    {
        $this->navidrome->method('filterMissingMediaFileIds')->willReturn([]);
        $this->subsonic->method('findPlaylistByName')->willReturn(self::playlist('pl-9'));
        $this->subsonic->expects($this->once())->method('replacePlaylist')->with('pl-9', 'Ma playlist', ['mf-1', 'mf-2']);

        $result = $this->tools()->create('Ma playlist', ['mf-1', 'mf-2', 'mf-1'], replace: true);

        $this->assertSame('pl-9', $result['id']);
        $this->assertTrue($result['replaced']);
        $this->assertSame(2, $result['track_count']);
    }

    public function testCreateCreatesAndComments(): void
    {
        $this->navidrome->method('filterMissingMediaFileIds')->willReturn([]);
        $this->subsonic->method('findPlaylistByName')->willReturn(null);
        $this->subsonic->expects($this->once())->method('createPlaylist')->with('Ma playlist', ['mf-1'])->willReturn('pl-1');
        $this->subsonic->expects($this->once())->method('updatePlaylist')->with('pl-1', null, 'Pour courir');

        $result = $this->tools()->create('  Ma playlist ', ['mf-1'], comment: 'Pour courir');

        $this->assertSame('pl-1', $result['id']);
        $this->assertFalse($result['replaced']);
    }

    public function testCreateStopsAtTheWriteGuard(): void
    {
        $this->guard->method('assertCanWrite')->willThrowException(new ToolCallException('mcp_write'));
        $this->navidrome->expects($this->never())->method('filterMissingMediaFileIds');

        $this->expectException(ToolCallException::class);
        $this->tools()->create('Ma playlist', ['mf-1']);
    }

    public function testDescribedRefusedWithoutAudioMuse(): void
    {
        $this->audioMuse->method('isConfigured')->willReturn(false);
        $this->creator->expects($this->never())->method('create');

        $this->expectException(ToolCallException::class);
        $this->tools()->createDescribed('Calme', 'piano calme');
    }

    public function testDescribedTurnsBusinessErrorIntoToolError(): void
    {
        $this->audioMuse->method('isConfigured')->willReturn(true);
        $this->creator->method('create')->willThrowException(new UserFacingException('Nom déjà pris.'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Nom déjà pris.');
        $this->tools()->createDescribed('Calme', 'piano calme');
    }

    public function testDescribedReturnsSlug(): void
    {
        $this->audioMuse->method('isConfigured')->willReturn(true);
        $playlist = new DescribedPlaylist('Calme', 'piano calme', 30);
        (new \ReflectionProperty(DescribedPlaylist::class, 'id'))->setValue($playlist, 7);
        $this->creator->method('create')->willReturn($playlist);

        $result = $this->tools()->createDescribed('Calme', 'piano calme');

        $this->assertSame('decrite-7', $result['slug']);
    }

    public function testRenameRenamesAnOwnedPlaylist(): void
    {
        $this->subsonic->method('findOwnedPlaylist')->with('pl-9')->willReturn(self::playlist('pl-9'));
        $this->subsonic->method('findPlaylistByName')->willReturn(null);
        $this->subsonic->expects($this->once())->method('updatePlaylist')->with('pl-9', 'Pour courir', null);

        $result = $this->tools()->rename('pl-9', '  Pour courir ');

        $this->assertSame('Ma playlist', $result['previous_name']);
        $this->assertSame('Pour courir', $result['name']);
    }

    public function testRenameRefusesUnknownOrForeignPlaylist(): void
    {
        $this->subsonic->method('findOwnedPlaylist')->willReturn(null);
        $this->subsonic->expects($this->never())->method('updatePlaylist');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('pl-x');
        $this->tools()->rename('pl-x', 'Pour courir');
    }

    public function testRenameRefusesAGeneratedPlaylist(): void
    {
        $this->subsonic->method('findOwnedPlaylist')->willReturn(self::playlist('pl-1', 'Top du mois dernier'));
        $this->subsonic->expects($this->never())->method('updatePlaylist');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('top-mois');
        $this->tools()->rename('pl-1', 'Autre nom');
    }

    public function testRenameRefusesTheNameOfAGeneratedPlaylist(): void
    {
        $this->subsonic->method('findOwnedPlaylist')->willReturn(self::playlist('pl-9'));
        $this->subsonic->expects($this->never())->method('updatePlaylist');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('écrasée');
        $this->tools()->rename('pl-9', 'TOP du mois dernier');
    }

    public function testRenameRefusesTheNameOfAnotherPlaylist(): void
    {
        $this->subsonic->method('findOwnedPlaylist')->willReturn(self::playlist('pl-9'));
        $this->subsonic->method('findPlaylistByName')->with('Pour courir')->willReturn(self::playlist('pl-2', 'Pour courir'));
        $this->subsonic->expects($this->never())->method('updatePlaylist');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('pl-2');
        $this->tools()->rename('pl-9', 'Pour courir');
    }

    public function testRenameToTheSameNameWritesNothing(): void
    {
        $this->subsonic->method('findOwnedPlaylist')->willReturn(self::playlist('pl-9'));
        $this->subsonic->expects($this->never())->method('updatePlaylist');

        $result = $this->tools()->rename('pl-9', 'Ma playlist');

        $this->assertStringContainsString('rien n\'a été modifié', $result['message']);
    }

    public function testRenameStopsAtTheWriteGuard(): void
    {
        $this->guard->method('assertCanWrite')->willThrowException(new ToolCallException('mcp_write'));
        $this->subsonic->expects($this->never())->method('findOwnedPlaylist');

        $this->expectException(ToolCallException::class);
        $this->tools()->rename('pl-9', 'Pour courir');
    }

    public function testDeleteDeletesAndReturnsTrackIds(): void
    {
        $this->subsonic->method('findOwnedPlaylist')->with('pl-9')->willReturn(self::playlist('pl-9'));
        $this->subsonic->method('getPlaylist')->with('pl-9')->willReturn(self::playlist('pl-9') + [
            'tracks' => [self::track('mf-2'), self::track('mf-1')],
        ]);
        $this->subsonic->expects($this->once())->method('deletePlaylist')->with('pl-9');

        $result = $this->tools()->delete('pl-9', 'Ma playlist');

        $this->assertSame(['mf-2', 'mf-1'], $result['track_ids']);
        $this->assertSame(2, $result['track_count']);
    }

    public function testDeleteRefusesWhenTheNameDoesNotMatch(): void
    {
        $this->subsonic->method('findOwnedPlaylist')->willReturn(self::playlist('pl-9'));
        $this->subsonic->expects($this->never())->method('deletePlaylist');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('« Ma playlist », pas « ma playlist »');
        $this->tools()->delete('pl-9', 'ma playlist');
    }

    public function testDeleteRefusesAGeneratedPlaylist(): void
    {
        $this->subsonic->method('findOwnedPlaylist')->willReturn(self::playlist('pl-1', 'Top du mois dernier'));
        $this->subsonic->expects($this->never())->method('deletePlaylist');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Désactiver sa définition');
        $this->tools()->delete('pl-1', 'Top du mois dernier');
    }

    public function testDeleteRefusesUnknownOrForeignPlaylist(): void
    {
        $this->subsonic->method('findOwnedPlaylist')->willReturn(null);
        $this->subsonic->expects($this->never())->method('deletePlaylist');

        $this->expectException(ToolCallException::class);
        $this->tools()->delete('pl-x', 'Ma playlist');
    }

    public function testDeleteStopsAtTheWriteGuard(): void
    {
        $this->guard->method('assertCanWrite')->willThrowException(new ToolCallException('mcp_write'));
        $this->subsonic->expects($this->never())->method('deletePlaylist');

        $this->expectException(ToolCallException::class);
        $this->tools()->delete('pl-9', 'Ma playlist');
    }

    public function testGetRefusesAPlaylistThatIsNotVisible(): void
    {
        $this->subsonic->method('getPlaylists')->willReturn([self::playlist('pl-1')]);
        $this->subsonic->expects($this->never())->method('getPlaylist');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('playlists_list');
        $this->tools()->get('pl-404');
    }

    public function testGetPagesTracksAndSummarizesTheWholePlaylist(): void
    {
        $this->subsonic->method('getPlaylists')->willReturn([self::playlist('pl-1')]);
        $this->subsonic->method('getPlaylist')->with('pl-1')->willReturn(self::playlist('pl-1') + ['tracks' => [
            ['artist' => 'B', 'year' => 1994] + self::track('mf-1'),
            ['artist' => 'A', 'year' => 1971] + self::track('mf-2'),
            ['artist' => 'B', 'year' => 2003] + self::track('mf-3'),
            ['artist' => 'C', 'year' => 0] + self::track('mf-4'),
        ]]);
        $this->navidrome->expects($this->once())->method('getPlayCountsByMediaFileId')
            ->with(['mf-2', 'mf-3'])->willReturn(['mf-2' => 7, 'mf-3' => 0]);

        $result = $this->tools()->get(' pl-1 ', limit: 2, offset: 1);

        $this->assertSame(4, $result['track_count']);
        $this->assertSame(['mf-2', 'mf-3'], array_column($result['tracks'], 'id'));
        $this->assertSame(7, $result['tracks'][0]['play_count']);
        $this->assertSame(['artist' => 'B', 'track_count' => 2], $result['summary']['artists'][0]);
        $this->assertSame(['min' => 1971, 'max' => 2003], $result['summary']['years']);
        $this->assertSame(['1970s' => 1, '1990s' => 1, '2000s' => 1], $result['summary']['decades']);
        $this->assertStringContainsString('offset', $result['message']);
    }

    public function testSimilarIsRefusedWithoutAudioMuse(): void
    {
        $this->audioMuse->method('isConfigured')->willReturn(false);
        $this->audioMuse->expects($this->never())->method('similarTracks');

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('AUDIOMUSE_BASE_URL');
        $this->tools()->similar(['mf-1']);
    }

    public function testSimilarMergesNeighboursExcludesSourcesAndRanksThem(): void
    {
        $this->audioMuse->method('isConfigured')->willReturn(true);
        $this->audioMuse->method('similarTracks')->willReturnCallback(static fn (string $seed): array => match ($seed) {
            'mf-1' => [
                ['item_id' => 'mf-2', 'distance' => 0.1],
                ['item_id' => 'x-near', 'distance' => 0.2],
                ['item_id' => 'x-both', 'distance' => 0.5],
                ['item_id' => 'x-banned', 'distance' => 0.05],
            ],
            'mf-2' => [
                ['item_id' => 'x-both', 'distance' => 0.4],
                ['item_id' => 'x-gone', 'distance' => 0.01],
            ],
            default => throw new AudioMuseNotFoundException('not analysed'),
        });
        $this->navidrome->expects($this->once())->method('getTrackSummaries')
            ->with(['x-both', 'x-gone', 'x-near'])
            ->willReturnCallback(static fn (array $ids): array => [
                'x-both' => self::summary('x-both'),
                'x-near' => self::summary('x-near'),
            ]);

        $result = $this->tools()->similar(['mf-1', 'mf-2', 'mf-new'], exclude_ids: ['x-banned']);

        $this->assertSame(['x-both', 'x-near'], array_column($result['items'], 'id'));
        $this->assertSame(2, $result['items'][0]['seed_count']);
        $this->assertSame(0.4, $result['items'][0]['distance']);
        $this->assertSame(['mf-new'], $result['seeds_not_analysed']);
    }

    public function testSimilarFailsLoudlyOnAudioMuseAuthErrors(): void
    {
        $this->audioMuse->method('isConfigured')->willReturn(true);
        $this->audioMuse->method('similarTracks')->willThrowException(new AudioMuseAuthException('401'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('authentification');
        $this->tools()->similar(['mf-1']);
    }

    public function testSimilarRefusesTooManySources(): void
    {
        $this->audioMuse->method('isConfigured')->willReturn(true);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('50 au plus');
        $this->tools()->similar(array_map(static fn (int $i): string => 'mf-' . $i, range(1, 51)));
    }

    private function tools(): PlaylistTools
    {
        return new PlaylistTools($this->navidrome, $this->subsonic, $this->generator, $this->creator, $this->audioMuse, $this->guard);
    }

    /**
     * @return array{id: string, name: string, owner: string, songCount: int, duration: int, public: bool, created: ?string, changed: ?string, comment: string}
     */
    private static function playlist(string $id, string $name = 'Ma playlist'): array
    {
        return [
            'id' => $id, 'name' => $name, 'owner' => 'admin', 'songCount' => 4, 'duration' => 0,
            'public' => false, 'created' => null, 'changed' => null, 'comment' => '',
        ];
    }

    /**
     * @return array{id: string, title: string, artist: string, album: string, year: ?int, duration: int, play_count: int}
     */
    private static function summary(string $id): array
    {
        return ['id' => $id, 'title' => 't', 'artist' => 'a', 'album' => 'b', 'year' => null, 'duration' => 0, 'play_count' => 0];
    }

    /**
     * @return array{id: string, title: string, artist: string, album: string, duration: int, playCount: int, year: ?int, starred: ?string}
     */
    private static function track(string $id): array
    {
        return ['id' => $id, 'title' => 't', 'artist' => 'a', 'album' => 'b', 'duration' => 0, 'playCount' => 0, 'year' => null, 'starred' => null];
    }
}
