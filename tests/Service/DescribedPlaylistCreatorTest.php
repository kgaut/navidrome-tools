<?php

namespace App\Tests\Service;

use App\Entity\DescribedPlaylist;
use App\Exception\UserFacingException;
use App\Message\GeneratePlaylistsMessage;
use App\Playlist\PlaylistGenerator;
use App\Service\DescribedPlaylistCreator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class DescribedPlaylistCreatorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, int, ?int}>
     */
    public static function invalid(): iterable
    {
        yield 'empty name' => ['  ', 'piano', 30, null];
        yield 'name of a generated playlist' => ['top du mois dernier', 'piano', 30, null];
        yield 'empty query' => ['Calme', '', 30, null];
        yield 'too many tracks' => ['Calme', 'piano', 201, null];
        yield 'negative cap' => ['Calme', 'piano', 30, -1];
    }

    #[DataProvider('invalid')]
    public function testRefusesInvalidInput(string $name, string $query, int $count, ?int $maxPlays): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $this->expectException(UserFacingException::class);
        $this->creator($em, $this->createMock(MessageBusInterface::class))->create($name, $query, $count, $maxPlays);
    }

    public function testPersistsThenQueuesGeneration(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->willReturnCallback(
            static fn (DescribedPlaylist $p) => (new \ReflectionProperty(DescribedPlaylist::class, 'id'))->setValue($p, 12),
        );
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')
            ->with($this->callback(static fn (GeneratePlaylistsMessage $m): bool => $m->slug === 'decrite-12'))
            ->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

        $playlist = $this->creator($em, $bus)->create(' Calme ', 'piano calme', 30, null);

        $this->assertSame('Calme', $playlist->getName());
    }

    private function creator(EntityManagerInterface $em, MessageBusInterface $bus): DescribedPlaylistCreator
    {
        $generator = $this->createMock(PlaylistGenerator::class);
        $generator->method('listDefinitions')->willReturn([
            ['slug' => 'top-mois', 'name' => 'Top du mois dernier', 'description' => '', 'enabled' => true],
        ]);

        return new DescribedPlaylistCreator($generator, $em, $bus);
    }
}
