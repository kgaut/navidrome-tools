<?php

namespace App\Tests\Strawberry;

use App\Strawberry\StrawberryRepository;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

class StrawberryRepositoryTest extends TestCase
{
    private string $dbPath;
    private StrawberryRepository $repo;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/strawberry-test-' . uniqid() . '.db';
        $this->repo = new StrawberryRepository($this->dbPath);

        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->dbPath]);
        $conn->executeStatement(<<<'SQL'
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
        $conn->close();
    }

    protected function tearDown(): void
    {
        $this->repo->close();
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testIsAvailableReturnsFalseWhenPathEmpty(): void
    {
        $repo = new StrawberryRepository('');
        $this->assertFalse($repo->isAvailable());
    }

    public function testIsAvailableReturnsTrueWhenPathSet(): void
    {
        $this->assertTrue($this->repo->isAvailable());
    }

    public function testFindSongsByArtistTitleExactMatch(): void
    {
        $this->insertSong('Artist', 'Song Title', '', 5, 1000);
        $this->insertSong('Other Artist', 'Different Song', '', 0, -1);

        $rows = $this->repo->findSongsByArtistTitle('Artist', 'Song Title');

        $this->assertCount(1, $rows);
        $this->assertSame(5, $rows[0]['playcount']);
        $this->assertSame(1000, $rows[0]['lastplayed']);
    }

    public function testFindSongsByArtistTitleCaseInsensitive(): void
    {
        $this->insertSong('Daft Punk', 'Get Lucky', '', 10, 2000);

        $rows = $this->repo->findSongsByArtistTitle('DAFT PUNK', 'GET LUCKY');
        $this->assertCount(1, $rows);
    }

    public function testFindSongsByArtistTitleAccentInsensitiveViaNormalize(): void
    {
        // Exact bytes match (same accents on both sides) goes through the SQL
        // filter and the PHP normalize() confirms the match.
        $this->insertSong('Björk', 'Jóga', '', 3, 500);

        $rows = $this->repo->findSongsByArtistTitle('Björk', 'Jóga');
        $this->assertCount(1, $rows);
    }

    public function testFindSongsByArtistTitleNoMatchReturnsEmpty(): void
    {
        $this->insertSong('Artist', 'Some Song', '', 0, -1);

        $rows = $this->repo->findSongsByArtistTitle('Artist', 'Other Song');
        $this->assertCount(0, $rows);
    }

    public function testFindSongByMbid(): void
    {
        $this->insertSong('Artist', 'Song', '', 0, -1, 'mbid-abc-123');

        $row = $this->repo->findSongByMbid('mbid-abc-123');

        $this->assertNotNull($row);
        $this->assertSame(0, $row['playcount']);
    }

    public function testFindSongByMbidNotFound(): void
    {
        $row = $this->repo->findSongByMbid('non-existent-mbid');
        $this->assertNull($row);
    }

    public function testFindSongByMbidEmptyStringReturnNull(): void
    {
        $row = $this->repo->findSongByMbid('');
        $this->assertNull($row);
    }

    public function testIncrementPlaycountAddsToExisting(): void
    {
        $this->insertSong('Artist', 'Song', '', 5, 1000);
        $rowid = $this->getLastRowid();

        $this->repo->incrementPlaycount($rowid, 3, 2000);

        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->dbPath]);
        $row = $conn->fetchAssociative('SELECT playcount, lastplayed FROM songs WHERE rowid = ?', [$rowid]);
        $conn->close();

        $this->assertSame(8, (int) $row['playcount']);
        $this->assertSame(2000, (int) $row['lastplayed']);
    }

    public function testIncrementPlaycountDoesNotDecreaseLastplayed(): void
    {
        $this->insertSong('Artist', 'Song', '', 5, 9999);
        $rowid = $this->getLastRowid();

        $this->repo->incrementPlaycount($rowid, 1, 1000);

        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->dbPath]);
        $row = $conn->fetchAssociative('SELECT lastplayed FROM songs WHERE rowid = ?', [$rowid]);
        $conn->close();

        $this->assertSame(9999, (int) $row['lastplayed']);
    }

    public function testFindSongsByArtistTitleFuzzyStripsFeating(): void
    {
        $this->insertSong('Orelsan', 'La fête est finie', '', 7, 800);

        // Last.fm may tag it as "Orelsan feat. Someone"
        $rows = $this->repo->findSongsByArtistTitleFuzzy('Orelsan feat. Someone', 'La fête est finie');
        $this->assertCount(1, $rows);
    }

    private function insertSong(
        string $artist,
        string $title,
        string $album,
        int $playcount,
        int $lastplayed,
        string $mbid = '',
    ): void {
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->dbPath]);
        $conn->executeStatement(
            'INSERT INTO songs (artist, title, album, albumartist, playcount, lastplayed, musicbrainz_recording_id) '
            . 'VALUES (:artist, :title, :album, :albumartist, :playcount, :lastplayed, :mbid)',
            [
                'artist' => $artist,
                'title' => $title,
                'album' => $album,
                'albumartist' => $artist,
                'playcount' => $playcount,
                'lastplayed' => $lastplayed,
                'mbid' => $mbid !== '' ? $mbid : null,
            ],
        );
        $conn->close();
    }

    private function getLastRowid(): int
    {
        $conn = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->dbPath]);
        $rowid = (int) $conn->fetchOne('SELECT max(rowid) FROM songs');
        $conn->close();

        return $rowid;
    }
}
