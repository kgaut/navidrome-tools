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

    public function testAggregatesByArtistSumsScrobblesAcrossTitles(): void
    {
        // Same artist, 3 different titles, 5 scrobbles in total.
        $this->insert('Gentleman', 'Berlin', null, '2026-01-01 10:00:00', 'unmatched');
        $this->insert('Gentleman', 'Berlin', null, '2026-02-01 10:00:00', 'unmatched');
        $this->insert('Gentleman', 'Soulfood', null, '2026-03-01 10:00:00', 'unmatched');
        $this->insert('Gentleman', 'Soulfood', null, '2026-03-02 10:00:00', 'unmatched');
        $this->insert('Gentleman', 'Dem Gone', null, '2026-04-01 10:00:00', 'unmatched');
        // Other artist with one scrobble.
        $this->insert('Hozier', 'Take Me to Church', null, '2026-01-15 10:00:00', 'unmatched');

        $r = LastFmImportTrackRepository::queryUnmatchedAggregatedByArtist($this->conn, null, 1, 10);

        $this->assertSame(2, $r['total']);
        $this->assertSame('Gentleman', $r['items'][0]['artist']);
        $this->assertSame(5, $r['items'][0]['scrobbles']);
        $this->assertSame(3, $r['items'][0]['distinct_titles']);
        $this->assertSame('2026-04-01 10:00:00', $r['items'][0]['last_played']->format('Y-m-d H:i:s'));

        $this->assertSame('Hozier', $r['items'][1]['artist']);
        $this->assertSame(1, $r['items'][1]['scrobbles']);
        $this->assertSame(1, $r['items'][1]['distinct_titles']);
    }

    public function testAggregateByArtistFiltersByArtistCaseInsensitive(): void
    {
        $this->insert('Gentleman', 'Berlin', null, '2026-01-01 10:00:00', 'unmatched');
        $this->insert('Hozier', 'Take Me to Church', null, '2026-01-01 10:00:00', 'unmatched');
        $this->insert('Gent Other', 'Track', null, '2026-01-01 10:00:00', 'unmatched');
        // Inserted/skipped/duplicate must be ignored.
        $this->insert('Gentle Skipped', 'Track', null, '2026-01-01 10:00:00', 'skipped');

        $r = LastFmImportTrackRepository::queryUnmatchedAggregatedByArtist($this->conn, 'gent', 1, 10);

        $this->assertSame(2, $r['total']);
        $artists = array_column($r['items'], 'artist');
        $this->assertContains('Gentleman', $artists);
        $this->assertContains('Gent Other', $artists);
    }

    public function testAggregateByArtistPagination(): void
    {
        foreach (range(1, 5) as $i) {
            $this->insert("Artist {$i}", 'Track', null, "2026-0{$i}-01 10:00:00", 'unmatched');
        }

        $page1 = LastFmImportTrackRepository::queryUnmatchedAggregatedByArtist($this->conn, null, 1, 2);
        $page2 = LastFmImportTrackRepository::queryUnmatchedAggregatedByArtist($this->conn, null, 2, 2);
        $page3 = LastFmImportTrackRepository::queryUnmatchedAggregatedByArtist($this->conn, null, 3, 2);

        $this->assertSame(5, $page1['total']);
        $this->assertCount(2, $page1['items']);
        $this->assertCount(2, $page2['items']);
        $this->assertCount(1, $page3['items']);

        $all = array_merge(
            array_column($page1['items'], 'artist'),
            array_column($page2['items'], 'artist'),
            array_column($page3['items'], 'artist'),
        );
        $this->assertCount(5, array_unique($all));
    }

    public function testAggregatesByAlbumGroupsArtistAlbumPair(): void
    {
        // Two artists with an album that happens to share the same name —
        // they must remain two separate rows.
        $this->insert('Artist A', 'Greatest Hits Vol 1', 'Greatest Hits', '2026-01-01 10:00:00', 'unmatched');
        $this->insert('Artist A', 'Greatest Hits Vol 2', 'Greatest Hits', '2026-02-01 10:00:00', 'unmatched');
        $this->insert('Artist B', 'Greatest Hits Vol 1', 'Greatest Hits', '2026-03-01 10:00:00', 'unmatched');

        $r = LastFmImportTrackRepository::queryUnmatchedAggregatedByAlbum($this->conn, null, null, 1, 10);

        $this->assertSame(2, $r['total']);
        // Sorted by scrobbles DESC: Artist A has 2 scrobbles, Artist B has 1.
        $this->assertSame('Artist A', $r['items'][0]['artist']);
        $this->assertSame('Greatest Hits', $r['items'][0]['album']);
        $this->assertSame(2, $r['items'][0]['scrobbles']);
        $this->assertSame(2, $r['items'][0]['distinct_titles']);
        $this->assertSame('Artist B', $r['items'][1]['artist']);
    }

    public function testAggregateByAlbumExcludesEmptyAlbum(): void
    {
        $this->insert('Artist', 'Track', null, '2026-01-01 10:00:00', 'unmatched');
        $this->insert('Artist', 'Track', '', '2026-01-01 10:00:00', 'unmatched');
        $this->insert('Artist', 'Track', 'Real Album', '2026-01-01 10:00:00', 'unmatched');

        $r = LastFmImportTrackRepository::queryUnmatchedAggregatedByAlbum($this->conn, null, null, 1, 10);

        $this->assertSame(1, $r['total']);
        $this->assertSame('Real Album', $r['items'][0]['album']);
    }

    public function testAggregateByAlbumFiltersAndPagination(): void
    {
        $this->insert('Gentleman', 'T1', 'Confidence', '2026-01-01 10:00:00', 'unmatched');
        $this->insert('Gentleman', 'T2', 'Confidence', '2026-01-02 10:00:00', 'unmatched');
        $this->insert('Gentleman', 'T1', 'Diversity', '2026-01-03 10:00:00', 'unmatched');
        $this->insert('Hozier', 'T1', 'Confidence', '2026-01-04 10:00:00', 'unmatched');

        // Filter by artist substring.
        $r = LastFmImportTrackRepository::queryUnmatchedAggregatedByAlbum($this->conn, 'gent', null, 1, 10);
        $this->assertSame(2, $r['total']);

        // Filter by album substring.
        $r = LastFmImportTrackRepository::queryUnmatchedAggregatedByAlbum($this->conn, null, 'confidence', 1, 10);
        $this->assertSame(2, $r['total']);

        // Combined filters.
        $r = LastFmImportTrackRepository::queryUnmatchedAggregatedByAlbum($this->conn, 'gent', 'confidence', 1, 10);
        $this->assertSame(1, $r['total']);
        $this->assertSame('Gentleman', $r['items'][0]['artist']);
        $this->assertSame('Confidence', $r['items'][0]['album']);

        // Pagination.
        $page1 = LastFmImportTrackRepository::queryUnmatchedAggregatedByAlbum($this->conn, null, null, 1, 2);
        $page2 = LastFmImportTrackRepository::queryUnmatchedAggregatedByAlbum($this->conn, null, null, 2, 2);
        $this->assertCount(2, $page1['items']);
        $this->assertCount(1, $page2['items']);
    }

    private function insert(string $artist, string $title, ?string $album, string $playedAt, string $status): void
    {
        $this->conn->executeStatement(
            'INSERT INTO lastfm_import_track (run_history_id, artist, title, album, played_at, status) VALUES (?, ?, ?, ?, ?, ?)',
            [1, $artist, $title, $album, $playedAt, $status]
        );
    }
}
