<?php

namespace App\Tests\Mcp;

use App\AudioMuse\AudioMuseClient;
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

    private function tools(): PlaylistTools
    {
        return new PlaylistTools($this->navidrome, $this->subsonic, $this->generator, $this->creator, $this->audioMuse, $this->guard);
    }

    /**
     * @return array{id: string, name: string, owner: string, songCount: int, duration: int, public: bool, created: ?string, changed: ?string, comment: string}
     */
    private static function playlist(string $id): array
    {
        return [
            'id' => $id, 'name' => 'Ma playlist', 'owner' => 'admin', 'songCount' => 4, 'duration' => 0,
            'public' => false, 'created' => null, 'changed' => null, 'comment' => '',
        ];
    }
}
