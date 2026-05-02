<?php

namespace App\Tests\Repository;

use App\Repository\LastFmImportTrackRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

class LastFmImportTrackRepositoryTest extends TestCase
{
    private string $dbPath;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/lastfm-track-repo-' . uniqid() . '.db';
        $this->conn = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $this->dbPath,
        ]);
        $this->conn->executeStatement(<<<'SQL'
            CREATE TABLE lastfm_import_track (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                run_history_id INTEGER NOT NULL,
                artist VARCHAR(255) NOT NULL,
                title VARCHAR(255) NOT NULL,
                album VARCHAR(255) NULL,
                mbid VARCHAR(64) NULL,
                played_at DATETIME NOT NULL,
                status VARCHAR(16) NOT NULL,
                matched_media_file_id VARCHAR(255) NULL
            )
        SQL);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testAggregatesByArtistTitleAlbumAndCountsScrobbles(): void
    {
        // Same triplet 3×, another triplet 1×.
        $this->insert('Gentleman', 'Berlin', null, '2026-01-01 10:00:00', 'unmatched');
        $this->insert('Gentleman', 'Berlin', null, '2026-02-01 10:00:00', 'unmatched');
        $this->insert('Gentleman', 'Berlin', null, '2026-03-01 10:00:00', 'unmatched');
        $this->insert('Hozier', 'Take Me to Church', 'Hozier', '2026-01-15 10:00:00', 'unmatched');

        $r = LastFmImportTrackRepository::queryUnmatchedAggregated($this->conn, null, null, null, 1, 10);

        $this->assertSame(2, $r['total']);
        $this->assertSame('Gentleman', $r['items'][0]['artist']);
        $this->assertSame('Berlin', $r['items'][0]['title']);
        $this->assertNull($r['items'][0]['album']);
        $this->assertSame(3, $r['items'][0]['scrobbles']);
        $this->assertSame('2026-03-01 10:00:00', $r['items'][0]['last_played']->format('Y-m-d H:i:s'));

        $this->assertSame('Hozier', $r['items'][1]['artist']);
        $this->assertSame('Hozier', $r['items'][1]['album']);
        $this->assertSame(1, $r['items'][1]['scrobbles']);
    }

    public function testIgnoresNonUnmatchedRows(): void
    {
        $this->insert('Inserted Artist', 'Track', null, '2026-01-01 10:00:00', 'inserted');
        $this->insert('Duplicate Artist', 'Track', null, '2026-01-01 10:00:00', 'duplicate');
        $this->insert('Skipped Artist', 'Track', null, '2026-01-01 10:00:00', 'skipped');
        $this->insert('Unmatched Artist', 'Track', null, '2026-01-01 10:00:00', 'unmatched');

        $r = LastFmImportTrackRepository::queryUnmatchedAggregated($this->conn, null, null, null, 1, 10);

        $this->assertSame(1, $r['total']);
        $this->assertSame('Unmatched Artist', $r['items'][0]['artist']);
    }

    public function testFiltersByArtistCaseInsensitive(): void
    {
        $this->insert('Gentleman', 'Berlin', null, '2026-01-01 10:00:00', 'unmatched');
        $this->insert('Hozier', 'Take Me to Church', null, '2026-01-01 10:00:00', 'unmatched');
        $this->insert('Gent Other', 'Track', null, '2026-01-01 10:00:00', 'unmatched');

        $r = LastFmImportTrackRepository::queryUnmatchedAggregated($this->conn, 'gent', null, null, 1, 10);
        $this->assertSame(2, $r['total']);
        $artists = array_column($r['items'], 'artist');
        $this->assertContains('Gentleman', $artists);
        $this->assertContains('Gent Other', $artists);
    }

    public function testFiltersByTitleAndAlbum(): void
    {
        $this->insert('Artist', 'Berlin', 'Album X', '2026-01-01 10:00:00', 'unmatched');
        $this->insert('Artist', 'Berlin', 'Album Y', '2026-01-01 10:00:00', 'unmatched');
        $this->insert('Artist', 'Other', 'Album X', '2026-01-01 10:00:00', 'unmatched');

        $r = LastFmImportTrackRepository::queryUnmatchedAggregated($this->conn, null, 'berlin', null, 1, 10);
        $this->assertSame(2, $r['total']);

        $r = LastFmImportTrackRepository::queryUnmatchedAggregated($this->conn, null, null, 'album x', 1, 10);
        $this->assertSame(2, $r['total']);

        $r = LastFmImportTrackRepository::queryUnmatchedAggregated($this->conn, null, 'berlin', 'album x', 1, 10);
        $this->assertSame(1, $r['total']);
    }

    public function testPagination(): void
    {
        // 5 distinct triplets, 1 scrobble each.
        foreach (range(1, 5) as $i) {
            $this->insert("Artist {$i}", 'Track', null, "2026-0{$i}-01 10:00:00", 'unmatched');
        }

        $page1 = LastFmImportTrackRepository::queryUnmatchedAggregated($this->conn, null, null, null, 1, 2);
        $this->assertCount(2, $page1['items']);
        $this->assertSame(5, $page1['total']);

        $page2 = LastFmImportTrackRepository::queryUnmatchedAggregated($this->conn, null, null, null, 2, 2);
        $this->assertCount(2, $page2['items']);

        $page3 = LastFmImportTrackRepository::queryUnmatchedAggregated($this->conn, null, null, null, 3, 2);
        $this->assertCount(1, $page3['items']);

        // No overlap between pages.
        $allTitles = array_merge(
            array_column($page1['items'], 'artist'),
            array_column($page2['items'], 'artist'),
            array_column($page3['items'], 'artist'),
        );
        $this->assertCount(5, array_unique($allTitles));
    }

    public function testEmptyAlbumNormalizedToNull(): void
    {
        $this->insert('Artist', 'Track', '', '2026-01-01 10:00:00', 'unmatched');

        $r = LastFmImportTrackRepository::queryUnmatchedAggregated($this->conn, null, null, null, 1, 10);
        $this->assertNull($r['items'][0]['album']);
    }

    public function testSortsByScrobblesDescThenLastPlayedDesc(): void
    {
        $this->insert('A', 'T', null, '2026-01-10 10:00:00', 'unmatched'); // 1 scrobble, played 2026-01-10
        $this->insert('B', 'T', null, '2026-01-01 10:00:00', 'unmatched'); // 2 scrobbles
        $this->insert('B', 'T', null, '2026-01-02 10:00:00', 'unmatched');
        $this->insert('C', 'T', null, '2026-01-15 10:00:00', 'unmatched'); // 1 scrobble, played 2026-01-15 (more recent than A)

        $r = LastFmImportTrackRepository::queryUnmatchedAggregated($this->conn, null, null, null, 1, 10);

        $this->assertSame('B', $r['items'][0]['artist']); // 2 scrobbles wins
        $this->assertSame('C', $r['items'][1]['artist']); // 1 scrobble, more recent
        $this->assertSame('A', $r['items'][2]['artist']); // 1 scrobble, older
    }

    private function insert(string $artist, string $title, ?string $album, string $playedAt, string $status): void
    {
        $this->conn->executeStatement(
            'INSERT INTO lastfm_import_track (run_history_id, artist, title, album, played_at, status) VALUES (?, ?, ?, ?, ?, ?)',
            [1, $artist, $title, $album, $playedAt, $status]
        );
    }
}
