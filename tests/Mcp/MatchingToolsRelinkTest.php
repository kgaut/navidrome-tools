<?php

namespace App\Tests\Mcp;

use App\Mcp\McpWriteGuard;
use App\Mcp\Tool\MatchingTools;
use App\Message\RelinkMatchedMessage;
use App\Repository\ScrobbleSyncRepository;
use App\Service\AliasManager;
use App\Service\MatchedRelinker;
use App\Service\UnmatchedDiagnoser;
use App\Service\UnmatchedRetrier;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Issue #285: matched_relink_preview / matched_relink.
 */
class MatchingToolsRelinkTest extends TestCase
{
    public function testRelinkRefusesACallWithoutArtistOrAlbumFilter(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->never())->method('dispatch');

        $this->expectException(ToolCallException::class);
        $this->tools($bus)->relink(title: 'Petit frère');
    }

    public function testRelinkChecksTheWriteScopeAndQueuesTheMessage(): void
    {
        $guard = $this->createMock(McpWriteGuard::class);
        $guard->expects($this->once())->method('assertCanWrite');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')
            ->with($this->callback(static fn (RelinkMatchedMessage $m) => $m->artist === 'IAM' && $m->fromAlbum === 'Instrumentales' && $m->limit === 2000))
            ->willReturnCallback(static fn (object $m) => new Envelope($m));

        $out = $this->tools($bus, $guard)->relink(artist: ' IAM ', from_album: 'Instrumentales', limit: 99999);

        $this->assertTrue($out['queued']);
    }

    public function testPreviewSummarizesThePlan(): void
    {
        $relinker = $this->createMock(MatchedRelinker::class);
        $relinker->method('plan')->with('IAM', null, null, null, 500)->willReturn([
            'considered' => 3,
            'moves' => [['sync_id' => 1, 'from' => 'a', 'to' => 'b', 'strategy' => 'triplet', 'played_at' => new \DateTimeImmutable()]],
            'groups' => [['artist' => 'IAM', 'title' => 'T', 'album' => 'A', 'from_id' => 'a', 'from_album' => 'X', 'to_id' => 'b', 'to_album' => 'A', 'strategy' => 'triplet', 'count' => 1]],
            'would_unmatch' => 1,
            'api_errors' => 0,
        ]);

        $out = $this->tools($this->createMock(MessageBusInterface::class), null, $relinker)->relinkPreview(artist: 'IAM');

        $this->assertSame(1, $out['to_move']);
        $this->assertSame(1, $out['would_unmatch']);
        $this->assertCount(1, $out['groups']);
    }

    private function tools(MessageBusInterface $bus, ?McpWriteGuard $guard = null, ?MatchedRelinker $relinker = null): MatchingTools
    {
        return new MatchingTools(
            $this->createMock(ScrobbleSyncRepository::class),
            $this->createMock(UnmatchedDiagnoser::class),
            $this->createMock(UnmatchedRetrier::class),
            $this->createMock(AliasManager::class),
            $guard ?? $this->createMock(McpWriteGuard::class),
            $relinker ?? $this->createMock(MatchedRelinker::class),
            $bus,
        );
    }
}
