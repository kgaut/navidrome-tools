<?php

namespace App\Tests\Playlist;

use App\Playlist\PlaylistContext;
use App\Playlist\PlaylistDefinitionInterface;
use App\Playlist\PlaylistGenerator;
use App\Playlist\PlaylistRunResult;
use App\Subsonic\SubsonicClient;
use PHPUnit\Framework\TestCase;

class PlaylistGeneratorTest extends TestCase
{
    public function testGenerateAllRunsEveryDefinition(): void
    {
        $a = $this->def('a', 'A', ['mf-1', 'mf-2']);
        $b = $this->def('b', 'B', ['mf-3']);

        $subsonic = $this->createMock(SubsonicClient::class);
        $subsonic->method('findPlaylistByName')->willReturn(null);
        $subsonic->expects($this->exactly(2))->method('createPlaylist')->willReturn('pl-new');

        $gen = new PlaylistGenerator([$a, $b], $subsonic);
        $results = $gen->generate(null, dryRun: false);

        // Both playlists generated — no enable list to filter on.
        $this->assertCount(2, $results);
        $this->assertSame(['a', 'b'], array_map(fn ($r) => $r->slug, $results));
    }

    public function testGenerateBySlugRunsThatOneOnly(): void
    {
        $a = $this->def('a', 'A', ['mf-1']);
        $b = $this->def('b', 'B', ['mf-3']);
        $subsonic = $this->createMock(SubsonicClient::class);
        $subsonic->method('findPlaylistByName')->willReturn(null);
        $subsonic->expects($this->once())->method('createPlaylist')->with('B', ['mf-3'])->willReturn('pl-b');

        $gen = new PlaylistGenerator([$a, $b], $subsonic);
        $results = $gen->generate('b', dryRun: false);

        $this->assertCount(1, $results);
        $this->assertSame('b', $results[0]->slug);
        $this->assertSame(PlaylistRunResult::ACTION_CREATED, $results[0]->action);
    }

    public function testUnknownSlugThrows(): void
    {
        $gen = new PlaylistGenerator([$this->def('a', 'A', ['mf-1'])], $this->createMock(SubsonicClient::class));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown playlist definition "nope"');
        $gen->generate('nope', dryRun: false);
    }

    public function testExistingPlaylistIsReplacedNotDuplicated(): void
    {
        $def = $this->def('a', 'A', ['mf-1', 'mf-2']);
        $subsonic = $this->createMock(SubsonicClient::class);
        $subsonic->method('findPlaylistByName')->with('A')->willReturn([
            'id' => 'pl-existing', 'name' => 'A', 'owner' => 'admin',
            'songCount' => 0, 'duration' => 0, 'public' => false,
            'created' => null, 'changed' => null, 'comment' => '',
        ]);
        $subsonic->expects($this->once())->method('replacePlaylist')->with('pl-existing', 'A', ['mf-1', 'mf-2']);
        $subsonic->expects($this->never())->method('createPlaylist');
        // Comment refreshed on the existing playlist too.
        $subsonic->expects($this->once())->method('updatePlaylist')->with('pl-existing', null, 'desc a');

        $results = (new PlaylistGenerator([$def], $subsonic))->generate('a', dryRun: false);

        $this->assertSame(PlaylistRunResult::ACTION_REPLACED, $results[0]->action);
        $this->assertSame('pl-existing', $results[0]->playlistId);
    }

    public function testDryRunNeverWrites(): void
    {
        $def = $this->def('a', 'A', ['mf-1', 'mf-2']);
        $subsonic = $this->createMock(SubsonicClient::class);
        $subsonic->expects($this->never())->method('createPlaylist');
        $subsonic->expects($this->never())->method('replacePlaylist');
        $subsonic->expects($this->never())->method('findPlaylistByName');
        $subsonic->expects($this->never())->method('updatePlaylist');

        $results = (new PlaylistGenerator([$def], $subsonic))->generate('a', dryRun: true);

        $this->assertSame(PlaylistRunResult::ACTION_DRY_RUN, $results[0]->action);
        $this->assertSame(['mf-1', 'mf-2'], $results[0]->trackIds);
    }

    public function testEmptyResultDoesNotWrite(): void
    {
        $def = $this->def('a', 'A', []);
        $subsonic = $this->createMock(SubsonicClient::class);
        $subsonic->expects($this->never())->method('createPlaylist');
        $subsonic->expects($this->never())->method('replacePlaylist');
        $subsonic->expects($this->never())->method('updatePlaylist');

        $results = (new PlaylistGenerator([$def], $subsonic))->generate('a', dryRun: false);

        $this->assertSame(PlaylistRunResult::ACTION_EMPTY, $results[0]->action);
    }

    public function testOneBrokenDefinitionDoesNotBlockOthers(): void
    {
        $broken = $this->createMock(PlaylistDefinitionInterface::class);
        $broken->method('getSlug')->willReturn('broken');
        $broken->method('getName')->willReturn('Broken');
        $broken->method('build')->willThrowException(new \RuntimeException('boom'));

        $ok = $this->def('ok', 'OK', ['mf-1']);

        $subsonic = $this->createMock(SubsonicClient::class);
        $subsonic->method('findPlaylistByName')->willReturn(null);
        $subsonic->method('createPlaylist')->willReturn('pl-ok');

        $results = (new PlaylistGenerator([$broken, $ok], $subsonic))->generate(null, dryRun: false);

        $this->assertCount(2, $results);
        $this->assertSame(PlaylistRunResult::ACTION_ERROR, $results[0]->action);
        $this->assertSame('boom', $results[0]->error);
        $this->assertSame(PlaylistRunResult::ACTION_CREATED, $results[1]->action);
    }

    public function testListDefinitionsReturnsSlugNameDescription(): void
    {
        $gen = new PlaylistGenerator(
            [$this->def('a', 'A', []), $this->def('b', 'B', [])],
            $this->createMock(SubsonicClient::class),
        );

        $list = $gen->listDefinitions();

        $this->assertSame(['a', 'b'], array_map(fn ($d) => $d['slug'], $list));
        $this->assertSame('A', $list[0]['name']);
        $this->assertSame('desc a', $list[0]['description']);
    }

    /**
     * @param list<string> $ids
     */
    private function def(string $slug, string $name, array $ids): PlaylistDefinitionInterface
    {
        return new class ($slug, $name, $ids) implements PlaylistDefinitionInterface {
            /** @param list<string> $ids */
            public function __construct(
                private readonly string $slug,
                private readonly string $name,
                private readonly array $ids,
            ) {
            }

            public function getSlug(): string
            {
                return $this->slug;
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getDescription(): string
            {
                return 'desc ' . $this->slug;
            }

            public function build(PlaylistContext $context): array
            {
                return $this->ids;
            }
        };
    }
}
