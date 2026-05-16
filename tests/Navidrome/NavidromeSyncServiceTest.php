<?php

namespace App\Tests\Navidrome;

use App\Entity\Scrobble;
use App\Entity\ScrobbleSync;
use App\LastFm\MatchResult;
use App\LastFm\ScrobbleMatcher;
use App\Navidrome\NavidromeDbBackup;
use App\Navidrome\NavidromeRepository;
use App\Navidrome\NavidromeSyncService;
use App\Repository\ScrobbleSyncRepository;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class NavidromeSyncServiceTest extends TestCase
{
    private string $ndDbPath;

    protected function setUp(): void
    {
        $this->ndDbPath = sys_get_temp_dir() . '/nd-sync-test-' . uniqid() . '.db';
        $conn = NavidromeFixtureFactory::createDatabase($this->ndDbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Get Lucky', 'Daft Punk');
        $conn->close();
    }

    protected function tearDown(): void
    {
        foreach ([$this->ndDbPath, $this->ndDbPath . '-wal', $this->ndDbPath . '-shm'] as $f) {
            if (file_exists($f)) {
                unlink($f);
            }
        }
    }

    public function testSyncMatchedScrobbleIsInsertedInNavidrome(): void
    {
        $scrobble = $this->makeScrobble('Daft Punk', 'Get Lucky', '2024-01-01 12:00:00');
        $sync = new ScrobbleSync($scrobble, ScrobbleSync::TARGET_NAVIDROME);

        $syncRepo = $this->createMock(ScrobbleSyncRepository::class);
        $syncRepo->method('prepareForTarget')->willReturn(1);
        $syncRepo->method('streamPending')->willReturnCallback(fn () => yield $sync);

        $ndRepo = new NavidromeRepository($this->ndDbPath, 'admin');
        $matcher = $this->createMock(ScrobbleMatcher::class);
        $matcher->method('match')->willReturn(MatchResult::matched('mf-1', 'couple', null));

        $backup = $this->createMock(NavidromeDbBackup::class);
        $backup->method('backup')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('contains')->willReturn(false);
        $em->method('persist');
        $em->method('flush');

        $service = new NavidromeSyncService($syncRepo, $matcher, $ndRepo, $backup, $em);
        $report = $service->process();

        $this->assertSame(1, $report->considered);
        $this->assertSame(1, $report->matched);
        $this->assertSame(0, $report->unmatched);

        $ndConn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->ndDbPath]);
        $count = (int) $ndConn->fetchOne('SELECT COUNT(*) FROM scrobbles');
        $this->assertSame(1, $count);
        $ndConn->close();
    }

    public function testSyncDryRunDoesNotWriteToNavidrome(): void
    {
        $scrobble = $this->makeScrobble('Daft Punk', 'Get Lucky', '2024-01-01 12:00:00');
        $sync = new ScrobbleSync($scrobble, ScrobbleSync::TARGET_NAVIDROME);

        $syncRepo = $this->createMock(ScrobbleSyncRepository::class);
        $syncRepo->method('prepareForTarget')->willReturn(1);
        $syncRepo->method('streamPending')->willReturnCallback(fn () => yield $sync);

        $ndRepo = new NavidromeRepository($this->ndDbPath, 'admin');
        $matcher = $this->createMock(ScrobbleMatcher::class);
        $matcher->method('match')->willReturn(MatchResult::matched('mf-1', 'couple', null));

        $backup = $this->createMock(NavidromeDbBackup::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('contains')->willReturn(false);

        $service = new NavidromeSyncService($syncRepo, $matcher, $ndRepo, $backup, $em);
        $report = $service->process(dryRun: true);

        $this->assertSame(1, $report->matched);

        $ndConn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->ndDbPath]);
        $count = (int) $ndConn->fetchOne('SELECT COUNT(*) FROM scrobbles');
        $this->assertSame(0, $count);
        $ndConn->close();
    }

    public function testUnmatchedScrobbleDoesNotInsert(): void
    {
        $scrobble = $this->makeScrobble('Unknown Artist', 'Unknown Song', '2024-01-01 12:00:00');
        $sync = new ScrobbleSync($scrobble, ScrobbleSync::TARGET_NAVIDROME);

        $syncRepo = $this->createMock(ScrobbleSyncRepository::class);
        $syncRepo->method('prepareForTarget')->willReturn(1);
        $syncRepo->method('streamPending')->willReturnCallback(fn () => yield $sync);

        $ndRepo = new NavidromeRepository($this->ndDbPath, 'admin');
        $matcher = $this->createMock(ScrobbleMatcher::class);
        $matcher->method('match')->willReturn(MatchResult::unmatched(null));

        $backup = $this->createMock(NavidromeDbBackup::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('contains')->willReturn(false);
        $em->method('persist');
        $em->method('flush');

        $service = new NavidromeSyncService($syncRepo, $matcher, $ndRepo, $backup, $em);
        $report = $service->process();

        $this->assertSame(0, $report->matched);
        $this->assertSame(1, $report->unmatched);
    }

    public function testPersistIsNeverCalledOnDetachedSync(): void
    {
        // Regression: detaching a sync before persist() crashes Doctrine ORM 3
        // with "Detached entity cannot be persisted", closing the EM and
        // surfacing to the user as "The EntityManager is closed."
        $scrobble = $this->makeScrobble('Daft Punk', 'Get Lucky', '2024-01-01 12:00:00');
        $sync = new ScrobbleSync($scrobble, ScrobbleSync::TARGET_NAVIDROME);

        $syncRepo = $this->createMock(ScrobbleSyncRepository::class);
        $syncRepo->method('prepareForTarget')->willReturn(1);
        $syncRepo->method('streamPending')->willReturnCallback(fn () => yield $sync);

        $ndRepo = new NavidromeRepository($this->ndDbPath, 'admin');
        $matcher = $this->createMock(ScrobbleMatcher::class);
        $matcher->method('match')->willReturn(MatchResult::matched('mf-1', 'couple', null));

        $backup = $this->createMock(NavidromeDbBackup::class);
        $backup->method('backup')->willReturn(null);

        $detached = new \SplObjectStorage();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('contains')->willReturnCallback(static fn (object $e): bool => !isset($detached[$e]));
        $em->method('detach')->willReturnCallback(static function (object $e) use ($detached): void {
            $detached[$e] = true;
        });
        $em->method('persist')->willReturnCallback(static function (object $e) use ($detached): void {
            if (isset($detached[$e])) {
                throw \Doctrine\ORM\ORMInvalidArgumentException::detachedEntityCannot($e, 'persisted');
            }
        });
        $em->method('flush');

        $service = new NavidromeSyncService($syncRepo, $matcher, $ndRepo, $backup, $em);
        $report = $service->process();

        $this->assertSame(1, $report->matched);
    }

    private function makeScrobble(string $artist, string $title, string $playedAt): Scrobble
    {
        return new Scrobble(
            lastfmUser: 'alice',
            artist: $artist,
            title: $title,
            album: null,
            albumArtist: null,
            mbidTrack: null,
            mbidArtist: null,
            mbidAlbum: null,
            playedAt: new \DateTimeImmutable($playedAt, new \DateTimeZone('UTC')),
        );
    }
}
