<?php

namespace App\Tests\MessageHandler;

use App\Entity\RunHistory;
use App\Message\GeneratePlaylistsMessage;
use App\MessageHandler\GeneratePlaylistsMessageHandler;
use App\Playlist\PlaylistGenerator;
use App\Playlist\PlaylistRunResult;
use App\Service\RunHistoryRecorder;
use PHPUnit\Framework\TestCase;

class GeneratePlaylistsMessageHandlerTest extends TestCase
{
    public function testHandlerGeneratesRequestedSlugAndRecordsRun(): void
    {
        $generator = $this->createMock(PlaylistGenerator::class);
        $generator->expects($this->once())
            ->method('generate')
            ->with('retour-en-arriere', false)
            ->willReturn([new PlaylistRunResult('retour-en-arriere', 'Retour en arrière', PlaylistRunResult::ACTION_CREATED, ['mf-1'])]);

        $recorder = $this->createMock(RunHistoryRecorder::class);
        $recorder->expects($this->once())
            ->method('record')
            ->with(
                RunHistory::TYPE_PLAYLIST_GENERATE,
                'retour-en-arriere',
                $this->stringContains('retour-en-arriere'),
                $this->isCallable(),
                $this->isCallable(),
            )
            // Execute the wrapped action so the generator is actually invoked.
            ->willReturnCallback(fn (string $t, string $r, string $l, callable $action) => $action());

        (new GeneratePlaylistsMessageHandler($generator, $recorder))(new GeneratePlaylistsMessage('retour-en-arriere'));
    }

    public function testHandlerWithNullSlugGeneratesAll(): void
    {
        $generator = $this->createMock(PlaylistGenerator::class);
        $generator->expects($this->once())->method('generate')->with(null, false)->willReturn([]);

        $recorder = $this->createMock(RunHistoryRecorder::class);
        $recorder->expects($this->once())
            ->method('record')
            ->with(RunHistory::TYPE_PLAYLIST_GENERATE, 'all', $this->anything(), $this->isCallable(), $this->isCallable())
            ->willReturnCallback(fn (string $t, string $r, string $l, callable $action) => $action());

        (new GeneratePlaylistsMessageHandler($generator, $recorder))(new GeneratePlaylistsMessage(null));
    }
}
