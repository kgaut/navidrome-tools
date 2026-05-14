<?php

namespace App\Tests\LastFm;

use App\LastFm\LastFmClient;
use App\LastFm\LastFmFetcher;
use App\LastFm\LastFmScrobble;
use App\Repository\ScrobbleRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class LastFmFetcherTest extends TestCase
{
    private string $dbPath;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/fetcher-test-' . uniqid() . '.db';
        $this->conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->dbPath]);
        $this->conn->executeStatement(<<<'SQL'
            CREATE TABLE scrobbles (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                lastfm_user VARCHAR(255) NOT NULL,
                artist VARCHAR(255) NOT NULL,
                title VARCHAR(255) NOT NULL,
                album VARCHAR(255) DEFAULT NULL,
                album_artist VARCHAR(255) DEFAULT NULL,
                mbid_track VARCHAR(64) DEFAULT NULL,
                mbid_artist VARCHAR(64) DEFAULT NULL,
                mbid_album VARCHAR(64) DEFAULT NULL,
                played_at DATETIME NOT NULL,
                loved BOOLEAN NOT NULL DEFAULT 0,
                image_url CLOB DEFAULT NULL,
                fetched_at DATETIME NOT NULL,
                UNIQUE (lastfm_user, played_at, artist, title)
            )
        SQL);
    }

    protected function tearDown(): void
    {
        $this->conn->close();
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testFetchInsertsScrobbles(): void
    {
        $client = $this->makeClient([
            $this->makeScrobble('Daft Punk', 'Get Lucky', '2024-01-01 12:00:00'),
            $this->makeScrobble('Radiohead', 'Creep', '2024-01-02 10:00:00'),
        ]);

        $fetcher = new LastFmFetcher($client, $this->makeRepo());
        $report = $fetcher->fetch('key', 'alice');

        $this->assertSame(2, $report->fetched);
        $this->assertSame(2, $report->inserted);
        $this->assertSame(0, $report->duplicates);
        $this->assertSame(2, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM scrobbles'));
    }

    public function testFetchDeduplicatesOnRerun(): void
    {
        $scrobbles = [
            $this->makeScrobble('Daft Punk', 'Get Lucky', '2024-01-01 12:00:00'),
        ];
        $client = $this->makeClient($scrobbles);
        $fetcher = new LastFmFetcher($client, $this->makeRepo());

        $fetcher->fetch('key', 'alice');
        // Re-fetch same data.
        $client2 = $this->makeClient($scrobbles);
        $fetcher2 = new LastFmFetcher($client2, $this->makeRepo());
        $report = $fetcher2->fetch('key', 'alice');

        $this->assertSame(1, $report->fetched);
        $this->assertSame(0, $report->inserted);
        $this->assertSame(1, $report->duplicates);
        $this->assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM scrobbles'));
    }

    public function testDryRunDoesNotWrite(): void
    {
        $client = $this->makeClient([
            $this->makeScrobble('Artist', 'Song', '2024-01-01 12:00:00'),
        ]);
        $fetcher = new LastFmFetcher($client, $this->makeRepo());
        $report = $fetcher->fetch('key', 'alice', dryRun: true);

        $this->assertSame(1, $report->fetched);
        $this->assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM scrobbles'));
    }

    public function testMaxScrobblesLimitsResults(): void
    {
        $client = $this->makeClient([
            $this->makeScrobble('A', 'T1', '2024-01-01 10:00:00'),
            $this->makeScrobble('A', 'T2', '2024-01-01 11:00:00'),
            $this->makeScrobble('A', 'T3', '2024-01-01 12:00:00'),
        ]);
        $fetcher = new LastFmFetcher($client, $this->makeRepo());
        $report = $fetcher->fetch('key', 'alice', maxScrobbles: 2);

        $this->assertSame(2, $report->fetched);
    }

    /** @param list<LastFmScrobble> $scrobbles */
    private function makeClient(array $scrobbles): LastFmClient
    {
        $client = $this->createMock(LastFmClient::class);
        $client->method('streamRecentTracks')->willReturnCallback(
            function () use ($scrobbles): \Generator {
                yield from $scrobbles;
            }
        );
        return $client;
    }

    private function makeRepo(): ScrobbleRepository
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->conn);

        $repo = $this->createMock(ScrobbleRepository::class);
        $repo->method('insertOrIgnore')->willReturnCallback(
            function (
                string $user,
                string $artist,
                string $title,
                ?string $album,
                ?string $albumArtist,
                ?string $mbidTrack,
                ?string $mbidArtist,
                ?string $mbidAlbum,
                \DateTimeImmutable $playedAt,
                bool $loved,
                ?string $imageUrl,
                string $fetchedAt,
            ): bool {
                $affected = $this->conn->executeStatement(
                    'INSERT OR IGNORE INTO scrobbles
                        (lastfm_user, artist, title, album, album_artist, mbid_track, mbid_artist, mbid_album,
                         played_at, loved, image_url, fetched_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$user, $artist, $title, $album, $albumArtist, $mbidTrack, $mbidArtist, $mbidAlbum,
                     $playedAt->format('Y-m-d H:i:s'), $loved ? 1 : 0, $imageUrl, $fetchedAt],
                );
                return $affected === 1;
            }
        );

        return $repo;
    }

    private function makeScrobble(string $artist, string $title, string $playedAt): LastFmScrobble
    {
        return new LastFmScrobble(
            artist: $artist,
            title: $title,
            album: '',
            albumArtist: '',
            mbid: null,
            mbidArtist: null,
            mbidAlbum: null,
            playedAt: new \DateTimeImmutable($playedAt, new \DateTimeZone('UTC')),
        );
    }
}
