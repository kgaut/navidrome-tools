<?php

namespace App\Tests\Navidrome;

use App\Navidrome\NavidromeRepository;
use PHPUnit\Framework\TestCase;

/**
 * {@see NavidromeRepository::searchMediaFiles()} (issue #276, MCP
 * `library_search`) against a real SQLite media_file table.
 */
class NavidromeSearchTest extends TestCase
{
    private string $path;
    private NavidromeRepository $repo;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/nd-search-' . uniqid() . '.db';
        $conn = NavidromeFixtureFactory::createDatabase($this->path);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Paranoid Android', 'Radiohead', album: 'OK Computer');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-2', 'Karma Police', 'Radiohead', album: 'OK Computer');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-3', 'Teardrop', 'Massive Attack', album: 'Mezzanine');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-4', 'Ok Computer Live', 'Tribute Band', album: 'Live', albumArtist: 'Radiohead');
        $conn->executeStatement("INSERT INTO scrobbles (media_file_id, user_id, submission_time) VALUES ('mf-2', 'user-1', 1700000000), ('mf-2', 'user-1', 1700000100)");
        $conn->close();
        $this->repo = new NavidromeRepository($this->path, 'admin');
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path . '-wal', $this->path . '-shm'] as $f) {
            if (file_exists($f)) {
                unlink($f);
            }
        }
    }

    public function testQueryMatchesTitleArtistOrAlbumCaseInsensitive(): void
    {
        $result = $this->repo->searchMediaFiles('ok computer', null, null, null, 10);

        $this->assertSame(3, $result['total']);
        $this->assertEqualsCanonicalizing(['mf-1', 'mf-2', 'mf-4'], array_column($result['items'], 'id'));
    }

    public function testCriteriaCombineAndArtistIncludesAlbumArtist(): void
    {
        $result = $this->repo->searchMediaFiles(null, 'radiohead', 'live', null, 10);

        $this->assertSame(1, $result['total']);
        $this->assertSame('mf-4', $result['items'][0]['id']);
    }

    public function testPaginationKeepsTheTotalAndCarriesPlayCounts(): void
    {
        $result = $this->repo->searchMediaFiles(null, null, null, 'ok computer', 1, 1);

        $this->assertSame(2, $result['total']);
        $this->assertCount(1, $result['items']);
        $this->assertSame('mf-1', $result['items'][0]['id']);

        $karma = $this->repo->searchMediaFiles(null, null, 'karma', null, 10)['items'][0];
        $this->assertSame(2, $karma['play_count']);
        $this->assertNull($karma['year']);
    }
}
