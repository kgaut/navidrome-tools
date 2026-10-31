<?php

namespace App\Tests\Service;

use App\Entity\LastFmAlias;
use App\Entity\LastFmArtistAlias;
use App\Exception\UserFacingException;
use App\Navidrome\NavidromeRepository;
use App\Repository\LastFmAliasRepository;
use App\Repository\LastFmArtistAliasRepository;
use App\Repository\LastFmMatchCacheRepository;
use App\Service\AliasManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AliasManagerTest extends TestCase
{
    private LastFmAliasRepository&MockObject $trackAliases;
    private LastFmArtistAliasRepository&MockObject $artistAliases;
    private LastFmMatchCacheRepository&MockObject $cache;
    private NavidromeRepository&MockObject $navidrome;
    private EntityManagerInterface&MockObject $em;

    protected function setUp(): void
    {
        $this->trackAliases = $this->createMock(LastFmAliasRepository::class);
        $this->artistAliases = $this->createMock(LastFmArtistAliasRepository::class);
        $this->cache = $this->createMock(LastFmMatchCacheRepository::class);
        $this->navidrome = $this->createMock(NavidromeRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
    }

    public function testTrackAliasPersistsAndPurgesTheCouple(): void
    {
        $this->navidrome->method('getMediaFileMetadata')->willReturn([['id' => 'mf-1', 'artist' => 'A', 'album' => '', 'year' => null, 'duration' => 0]]);
        $this->em->expects($this->once())->method('persist')->with($this->isInstanceOf(LastFmAlias::class));
        $this->cache->expects($this->once())->method('purgeByCouple')->with('Radiohed', 'Creep');

        $alias = $this->manager()->createTrackAlias(' Radiohed ', 'Creep', 'mf-1');

        $this->assertSame('mf-1', $alias->getTargetMediaFileId());
    }

    public function testTrackAliasWithoutTargetIgnoresTheCouple(): void
    {
        $this->navidrome->expects($this->never())->method('getMediaFileMetadata');

        $this->assertNull($this->manager()->createTrackAlias('A', 'B', '')->getTargetMediaFileId());
    }

    public function testTrackAliasRefusesUnknownTarget(): void
    {
        $this->navidrome->method('getMediaFileMetadata')->willReturn([]);
        $this->em->expects($this->never())->method('persist');

        $this->expectException(UserFacingException::class);
        $this->manager()->createTrackAlias('A', 'B', 'nope');
    }

    public function testTrackAliasRefusesExistingAlias(): void
    {
        $this->trackAliases->method('findByScrobble')->willReturn(new LastFmAlias('A', 'B', null));

        $this->expectException(UserFacingException::class);
        $this->expectExceptionMessage('existe déjà');
        $this->manager()->createTrackAlias('A', 'B', null);
    }

    public function testArtistAliasRequiresTargetInLibrary(): void
    {
        $this->navidrome->method('hasArtistInLibrary')->willReturn(false);

        $this->expectException(UserFacingException::class);
        $this->manager()->createArtistAlias('Radiohed', 'Inconnu');
    }

    public function testArtistAliasRefusesSameArtist(): void
    {
        $this->expectException(UserFacingException::class);
        $this->manager()->createArtistAlias('Radiohead', ' radiohead ');
    }

    public function testArtistAliasPersistsAndPurgesTheArtist(): void
    {
        $this->navidrome->method('hasArtistInLibrary')->willReturn(true);
        $this->em->expects($this->once())->method('persist')->with($this->isInstanceOf(LastFmArtistAlias::class));
        $this->cache->expects($this->once())->method('purgeByArtist')->with('Radiohed');

        $this->assertSame('Radiohead', $this->manager()->createArtistAlias('Radiohed', 'Radiohead')->getTargetArtist());
    }

    private function manager(): AliasManager
    {
        return new AliasManager($this->trackAliases, $this->artistAliases, $this->cache, $this->navidrome, $this->em);
    }
}
