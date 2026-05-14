<?php

namespace App\Tests\Strawberry;

use App\Entity\LastFmBufferedScrobble;
use App\Entity\RunHistory;
use App\Repository\LastFmBufferedScrobbleRepository;
use App\Strawberry\StrawberryBufferProcessor;
use App\Strawberry\StrawberryRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class StrawberryBufferProcessorTest extends TestCase
{
    private string $strawberryDbPath;
    private string $bufferDbPath;
    private Connection $bufferConn;
    private StrawberryRepository $strawberry;

    protected function setUp(): void
    {
        $this->strawberryDbPath = sys_get_temp_dir() . '/strawberry-proc-sb-' . uniqid() . '.db';
        $this->bufferDbPath = sys_get_temp_dir() . '/strawberry-proc-buf-' . uniqid() . '.db';

        $this->bufferConn = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $this->bufferDbPath,
        ]);
        $this->bufferConn->executeStatement(<<<'SQL'
            CREATE TABLE lastfm_import_buffer (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                lastfm_user VARCHAR(255) NOT NULL,
                artist VARCHAR(255) NOT NULL,
                title VARCHAR(255) NOT NULL,
                album VARCHAR(255) DEFAULT NULL,
                mbid VARCHAR(64) DEFAULT NULL,
                played_at DATETIME NOT NULL,
                fetched_at DATETIME NOT NULL,
                synced_navidrome BOOLEAN NOT NULL DEFAULT 0,
                synced_strawberry BOOLEAN NOT NULL DEFAULT 0,
                strawberry_attempted_at DATETIME DEFAULT NULL
            )
        SQL);

        $sbConn = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $this->strawberryDbPath,
        ]);
        $sbConn->executeStatement(<<<'SQL'
            CREATE TABLE songs (
                title TEXT,
                artist TEXT,
                albumartist TEXT,
                album TEXT,
                playcount INTEGER NOT NULL DEFAULT 0,
                lastplayed INTEGER NOT NULL DEFAULT -1,
                musicbrainz_recording_id TEXT,
                musicbrainz_track_id TEXT
            )
        SQL);
        $sbConn->close();

        $this->strawberry = new StrawberryRepository($this->strawberryDbPath);
    }

    protected function tearDown(): void
    {
        $this->strawberry->close();
        foreach ([$this->strawberryDbPath, $this->bufferDbPath] as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    public function testMatchedRowsIncrementPlaycountAndMarkSynced(): void
    {
        $this->insertSong('Daft Punk', 'Get Lucky', 5, 1000);
        $sbRowid = $this->getLastSongRowid();

        $rows = [
            $this->makeBuffered('Daft Punk', 'Get Lucky', new \DateTimeImmutable('2026-04-01 12:00:00')),
            $this->makeBuffered('Daft Punk', 'Get Lucky', new \DateTimeImmutable('2026-04-02 12:00:00')),
        ];
        $this->seedBuffer($rows);

        $processor = $this->makeProcessor($rows);
        $report = $processor->process();

        $this->assertSame(2, $report->considered);
        $this->assertSame(2, $report->matched);
        $this->assertSame(0, $report->unmatched);

        // Both scrobbles for the same rowid → playcount +2.
        $sbConn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->strawberryDbPath]);
        $row = $sbConn->fetchAssociative('SELECT playcount, lastplayed FROM songs WHERE rowid = ?', [$sbRowid]);
        $sbConn->close();
        $this->assertSame(7, (int) $row['playcount']);
        $this->assertSame((new \DateTimeImmutable('2026-04-02 12:00:00'))->getTimestamp(), (int) $row['lastplayed']);

        // Buffer rows must be marked synced and have attempted_at set.
        $synced = (int) $this->bufferConn->fetchOne(
            'SELECT COUNT(*) FROM lastfm_import_buffer WHERE synced_strawberry = 1 AND strawberry_attempted_at IS NOT NULL',
        );
        $this->assertSame(2, $synced);
    }

    public function testUnmatchedRowsAreNotMarkedSynced(): void
    {
        // No matching song in Strawberry.
        $rows = [
            $this->makeBuffered('Unknown Artist', 'Unknown Song', new \DateTimeImmutable('2026-04-01 12:00:00')),
        ];
        $this->seedBuffer($rows);

        $processor = $this->makeProcessor($rows);
        $report = $processor->process();

        $this->assertSame(0, $report->matched);
        $this->assertSame(1, $report->unmatched);

        // Row must be unsynced with attempted_at set (= unmatched, not just pending).
        $unmatched = (int) $this->bufferConn->fetchOne(
            'SELECT COUNT(*) FROM lastfm_import_buffer WHERE synced_strawberry = 0 AND strawberry_attempted_at IS NOT NULL',
        );
        $this->assertSame(1, $unmatched);
    }

    public function testDryRunDoesNotWriteAnything(): void
    {
        $this->insertSong('Radiohead', 'Creep', 0, -1);

        $rows = [
            $this->makeBuffered('Radiohead', 'Creep', new \DateTimeImmutable('2026-04-01 12:00:00')),
        ];
        $this->seedBuffer($rows);

        $processor = $this->makeProcessor($rows);
        $report = $processor->process(dryRun: true);

        $this->assertSame(1, $report->matched);

        // Strawberry playcount must not have changed.
        $sbConn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->strawberryDbPath]);
        $row = $sbConn->fetchAssociative('SELECT playcount FROM songs WHERE lower(title) = lower(?)', ['Creep']);
        $sbConn->close();
        $this->assertSame(0, (int) $row['playcount']);

        // Dry-run: row stays pending (synced=0, attempted_at=NULL).
        $pending = (int) $this->bufferConn->fetchOne(
            'SELECT COUNT(*) FROM lastfm_import_buffer WHERE synced_strawberry = 0 AND strawberry_attempted_at IS NULL',
        );
        $this->assertSame(1, $pending);
    }

    public function testDisabledIntegrationReturnsEmptyReport(): void
    {
        $disabled = new StrawberryRepository('');
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->bufferConn);

        $processor = new StrawberryBufferProcessor(
            $this->createMock(LastFmBufferedScrobbleRepository::class),
            $disabled,
            $em,
        );

        $report = $processor->process();
        $this->assertSame(0, $report->considered);
        $this->assertSame(0, $report->matched);
    }

    private function insertSong(string $artist, string $title, int $playcount, int $lastplayed): void
    {
        $sbConn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->strawberryDbPath]);
        $sbConn->executeStatement(
            'INSERT INTO songs (artist, title, albumartist, album, playcount, lastplayed) '
            . 'VALUES (:artist, :title, :albumartist, :album, :playcount, :lastplayed)',
            [
                'artist' => $artist,
                'title' => $title,
                'albumartist' => $artist,
                'album' => '',
                'playcount' => $playcount,
                'lastplayed' => $lastplayed,
            ],
        );
        $sbConn->close();
    }

    private function getLastSongRowid(): int
    {
        $sbConn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->strawberryDbPath]);
        $rowid = (int) $sbConn->fetchOne('SELECT max(rowid) FROM songs');
        $sbConn->close();

        return $rowid;
    }

    /** @param list<LastFmBufferedScrobble> $rows */
    private function seedBuffer(array $rows): void
    {
        foreach ($rows as $row) {
            $this->bufferConn->executeStatement(
                'INSERT INTO lastfm_import_buffer '
                . '(id, lastfm_user, artist, title, album, mbid, played_at, fetched_at, synced_navidrome, synced_strawberry, strawberry_attempted_at) '
                . 'VALUES (:id, :user, :artist, :title, :album, :mbid, :played, :fetched, 0, 0, NULL)',
                [
                    'id' => $row->getId(),
                    'user' => $row->getLastfmUser(),
                    'artist' => $row->getArtist(),
                    'title' => $row->getTitle(),
                    'album' => $row->getAlbum(),
                    'mbid' => $row->getMbid(),
                    'played' => $row->getPlayedAt()->format('Y-m-d H:i:s'),
                    'fetched' => $row->getFetchedAt()->format('Y-m-d H:i:s'),
                ],
            );
        }
    }

    /** @param list<LastFmBufferedScrobble> $rows */
    private function makeProcessor(array $rows): StrawberryBufferProcessor
    {
        $repo = $this->createMock(LastFmBufferedScrobbleRepository::class);
        $repo->method('streamUnsyncedStrawberry')->willReturnCallback(
            function (int $limit = 0, bool $includeUnmatched = false) use ($rows): \Generator {
                $emitted = 0;
                foreach ($rows as $row) {
                    if ($limit > 0 && $emitted >= $limit) {
                        break;
                    }
                    $cond = $includeUnmatched
                        ? 'synced_strawberry = 0'
                        : 'synced_strawberry = 0 AND strawberry_attempted_at IS NULL';
                    $notYetSynced = (int) $this->bufferConn->fetchOne(
                        'SELECT COUNT(*) FROM lastfm_import_buffer WHERE id = ? AND ' . $cond,
                        [$row->getId()],
                    );
                    if ($notYetSynced === 0) {
                        continue;
                    }
                    $emitted++;
                    yield $row;
                }
            }
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->bufferConn);
        $em->method('flush');

        return new StrawberryBufferProcessor($repo, $this->strawberry, $em);
    }

    private function makeBuffered(string $artist, string $title, \DateTimeImmutable $playedAt): LastFmBufferedScrobble
    {
        static $next = 1;
        $row = new LastFmBufferedScrobble(
            lastfmUser: 'alice',
            artist: $artist,
            title: $title,
            album: null,
            mbid: null,
            playedAt: $playedAt,
            fetchedAt: new \DateTimeImmutable('2026-05-14 00:00:00'),
        );
        $ref = new \ReflectionProperty(LastFmBufferedScrobble::class, 'id');
        $ref->setValue($row, $next++);

        return $row;
    }
}
