<?php

namespace App\Tests\Strawberry;

use App\Entity\Scrobble;
use App\Entity\ScrobbleSync;
use App\Repository\ScrobbleSyncRepository;
use App\Strawberry\StrawberryRepository;
use App\Strawberry\StrawberrySyncService;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class StrawberrySyncServiceTest extends TestCase
{
    private string $sbDbPath;

    protected function setUp(): void
    {
        $this->sbDbPath = sys_get_temp_dir() . '/sb-sync-test-' . uniqid() . '.db';
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->sbDbPath]);
        $conn->executeStatement(<<<'SQL'
            CREATE TABLE songs (
                title TEXT, artist TEXT, albumartist TEXT, album TEXT,
                playcount INTEGER NOT NULL DEFAULT 0,
                lastplayed INTEGER NOT NULL DEFAULT -1,
                musicbrainz_recording_id TEXT,
                musicbrainz_track_id TEXT
            )
        SQL);
        $conn->executeStatement("INSERT INTO songs (artist, title, albumartist, album, playcount, lastplayed) VALUES ('Daft Punk', 'Get Lucky', 'Daft Punk', 'RAM', 5, 1000)");
        $conn->close();
    }

    protected function tearDown(): void
    {
        if (file_exists($this->sbDbPath)) {
            unlink($this->sbDbPath);
        }
    }

    public function testMatchedScrobbleIncrementsPlaycount(): void
    {
        $scrobble = $this->makeScrobble('Daft Punk', 'Get Lucky', '2024-01-01 12:00:00');
        $sync = new ScrobbleSync($scrobble, ScrobbleSync::TARGET_STRAWBERRY);

        $syncRepo = $this->createMock(ScrobbleSyncRepository::class);
        $syncRepo->method('prepareForTarget')->willReturn(1);
        $syncRepo->method('streamPending')->willReturnCallback(fn () => yield $sync);
        $syncRepo->method('resetUnmatchedToPending')->willReturn(0);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('contains')->willReturn(false);
        $em->method('persist');
        $em->method('flush');

        $repo = new StrawberryRepository($this->sbDbPath);
        $service = new StrawberrySyncService($syncRepo, $repo, $em);
        $report = $service->process();

        $this->assertSame(1, $report->matched);
        $this->assertSame(0, $report->unmatched);

        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->sbDbPath]);
        $row = $conn->fetchAssociative("SELECT playcount, lastplayed FROM songs WHERE lower(title) = lower('Get Lucky')");
        $conn->close();

        $this->assertSame(6, (int) $row['playcount']);
        $this->assertGreaterThan(1000, (int) $row['lastplayed']);
    }

    public function testUnmatchedScrobbleDoesNotWrite(): void
    {
        $scrobble = $this->makeScrobble('Unknown', 'Unknown Song', '2024-01-01 12:00:00');
        $sync = new ScrobbleSync($scrobble, ScrobbleSync::TARGET_STRAWBERRY);

        $syncRepo = $this->createMock(ScrobbleSyncRepository::class);
        $syncRepo->method('prepareForTarget')->willReturn(1);
        $syncRepo->method('streamPending')->willReturnCallback(fn () => yield $sync);
        $syncRepo->method('resetUnmatchedToPending')->willReturn(0);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('contains')->willReturn(false);
        $em->method('persist');
        $em->method('flush');

        $repo = new StrawberryRepository($this->sbDbPath);
        $service = new StrawberrySyncService($syncRepo, $repo, $em);
        $report = $service->process();

        $this->assertSame(0, $report->matched);
        $this->assertSame(1, $report->unmatched);

        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->sbDbPath]);
        $playcount = (int) $conn->fetchOne("SELECT playcount FROM songs WHERE lower(title) = lower('Get Lucky')");
        $conn->close();

        $this->assertSame(5, $playcount);
    }

    public function testPersistIsNeverCalledOnDetachedSync(): void
    {
        // Regression: detaching a sync before persist() crashes Doctrine ORM 3
        // with "Detached entity cannot be persisted", which closes the EM and
        // surfaces to the user as "The EntityManager is closed."
        $scrobble = $this->makeScrobble('Daft Punk', 'Get Lucky', '2024-01-01 12:00:00');
        $sync = new ScrobbleSync($scrobble, ScrobbleSync::TARGET_STRAWBERRY);

        $syncRepo = $this->createMock(ScrobbleSyncRepository::class);
        $syncRepo->method('prepareForTarget')->willReturn(1);
        $syncRepo->method('streamPending')->willReturnCallback(fn () => yield $sync);
        $syncRepo->method('resetUnmatchedToPending')->willReturn(0);

        $detached = new \SplObjectStorage();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('contains')->willReturnCallback(static fn (object $e): bool => !isset($detached[$e]));
        $em->method('detach')->willReturnCallback(static function (object $e) use ($detached): void {
            $detached[$e] = true;
        });
        $em->method('persist')->willReturnCallback(static function (object $e) use ($detached): void {
            if (isset($detached[$e])) {
                throw new \Doctrine\ORM\Exception\ORMException('Detached entity cannot be persisted');
            }
        });
        $em->method('flush');

        $repo = new StrawberryRepository($this->sbDbPath);
        $service = new StrawberrySyncService($syncRepo, $repo, $em);

        // Must not throw — would mean we detached before persisting.
        $report = $service->process();

        $this->assertSame(1, $report->matched);
    }

    public function testDryRunDoesNotWrite(): void
    {
        $scrobble = $this->makeScrobble('Daft Punk', 'Get Lucky', '2024-01-01 12:00:00');
        $sync = new ScrobbleSync($scrobble, ScrobbleSync::TARGET_STRAWBERRY);

        $syncRepo = $this->createMock(ScrobbleSyncRepository::class);
        $syncRepo->method('prepareForTarget')->willReturn(1);
        $syncRepo->method('streamPending')->willReturnCallback(fn () => yield $sync);
        $syncRepo->method('resetUnmatchedToPending')->willReturn(0);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('contains')->willReturn(false);

        $repo = new StrawberryRepository($this->sbDbPath);
        $service = new StrawberrySyncService($syncRepo, $repo, $em);
        $report = $service->process(dryRun: true);

        $this->assertSame(1, $report->matched);

        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->sbDbPath]);
        $playcount = (int) $conn->fetchOne("SELECT playcount FROM songs WHERE lower(title) = lower('Get Lucky')");
        $conn->close();

        $this->assertSame(5, $playcount);
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
