<?php

namespace App\Tests\Navidrome;

use App\Navidrome\NavidromeRepository;
use PHPUnit\Framework\TestCase;

class NavidromeRepositoryStatsTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/navidrome-stats-' . uniqid() . '.db';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testTotalPlaysAndDistinctTracksWithScrobbles(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'A', 'Artist X');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-2', 'B', 'Artist Y');

        for ($i = 0; $i < 5; $i++) {
            NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', date('Y-m-d H:i:s', strtotime("-{$i} day - 1 hour")));
        }
        for ($i = 0; $i < 2; $i++) {
            NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-2', date('Y-m-d H:i:s', strtotime("-{$i} day - 1 hour")));
        }

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $now = new \DateTimeImmutable();
        $weekAgo = $now->modify('-7 day');

        $this->assertSame(7, $repo->getTotalPlays($weekAgo, $now));
        $this->assertSame(2, $repo->getDistinctTracksPlayed($weekAgo, $now));
    }

    public function testScrobbleClientHelpersDetectColumnAndListDistinctClients(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'A', 'Artist');

        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', '2025-01-01 10:00:00', 'Symfonium');
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', '2025-01-02 10:00:00', 'DSub');
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', '2025-01-03 10:00:00', 'Symfonium');
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', '2025-01-04 10:00:00', null);

        $repo = new NavidromeRepository($this->dbPath, 'admin');

        $this->assertTrue($repo->hasScrobbleClient(), 'fixture creates the column');
        $this->assertSame(['DSub', 'Symfonium'], $repo->listScrobbleClients(), 'sorted, NULL excluded');
    }

    public function testStatsMethodsFilterByClientWhenColumnIsPresent(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Song 1', 'Artist X');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-2', 'Song 2', 'Artist Y');

        // 3 Symfonium plays for Artist X, 2 DSub plays for Artist Y
        for ($i = 0; $i < 3; $i++) {
            NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', '2025-01-0' . ($i + 1) . ' 10:00:00', 'Symfonium');
        }
        for ($i = 0; $i < 2; $i++) {
            NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-2', '2025-01-0' . ($i + 1) . ' 10:00:00', 'DSub');
        }

        $repo = new NavidromeRepository($this->dbPath, 'admin');

        $this->assertSame(5, $repo->getTotalPlays(null, null), 'baseline: all plays');
        $this->assertSame(3, $repo->getTotalPlays(null, null, 'Symfonium'));
        $this->assertSame(2, $repo->getTotalPlays(null, null, 'DSub'));

        $topArtistsAll = $repo->getTopArtists(null, null, 10);
        $this->assertCount(2, $topArtistsAll);

        $topArtistsSymf = $repo->getTopArtists(null, null, 10, 'Symfonium');
        $this->assertCount(1, $topArtistsSymf);
        $this->assertSame('Artist X', $topArtistsSymf[0]['artist']);
        $this->assertSame(3, $topArtistsSymf[0]['plays']);
    }

    public function testTopArtistsAggregatesCorrectly(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Song 1', 'Daft Punk');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-2', 'Song 2', 'Daft Punk');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-3', 'Song 3', 'Aphex Twin');

        // Daft Punk: 2 + 3 = 5 plays. Aphex Twin: 4 plays.
        for ($i = 0; $i < 2; $i++) {
            NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', date('Y-m-d H:i:s', strtotime("-{$i} day - 1 hour")));
        }
        for ($i = 0; $i < 3; $i++) {
            NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-2', date('Y-m-d H:i:s', strtotime("-{$i} day - 1 hour")));
        }
        for ($i = 0; $i < 4; $i++) {
            NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-3', date('Y-m-d H:i:s', strtotime("-{$i} day - 1 hour")));
        }

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $now = new \DateTimeImmutable();
        $monthAgo = $now->modify('-30 day');

        $top = $repo->getTopArtists($monthAgo, $now, 10);

        $this->assertSame([
            ['artist' => 'Daft Punk', 'plays' => 5],
            ['artist' => 'Aphex Twin', 'plays' => 4],
        ], $top);
    }

    public function testTopTracksWithDetailsIncludesAlbumAndArtist(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Hot Track', 'Some Artist');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-2', 'Cold Track', 'Some Artist');

        for ($i = 0; $i < 8; $i++) {
            NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', date('Y-m-d H:i:s', strtotime("-{$i} day - 1 hour")));
        }
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-2', date('Y-m-d H:i:s', strtotime('-1 hour')));

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $now = new \DateTimeImmutable();
        $top = $repo->getTopTracksWithDetails($now->modify('-30 day'), $now, 50);

        $this->assertCount(2, $top);
        $this->assertSame('mf-1', $top[0]['id']);
        $this->assertSame('Hot Track', $top[0]['title']);
        $this->assertSame('Some Artist', $top[0]['artist']);
        $this->assertSame('Album', $top[0]['album']);
        $this->assertSame(8, $top[0]['plays']);
        $this->assertSame(1, $top[1]['plays']);
    }

    public function testAllTimeUsesAnnotationFallback(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: false);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Old hit', 'Past Me');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-2', 'Niche', 'Past Me');
        NavidromeFixtureFactory::insertAnnotation($conn, 'user-1', 'mf-1', 42, '2010-01-01 00:00:00');
        NavidromeFixtureFactory::insertAnnotation($conn, 'user-1', 'mf-2', 8, '2012-01-01 00:00:00');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $this->assertSame(50, $repo->getTotalPlays(null, null));
        $this->assertSame(2, $repo->getDistinctTracksPlayed(null, null));

        $artists = $repo->getTopArtists(null, null, 5);
        $this->assertSame([['artist' => 'Past Me', 'plays' => 50]], $artists);
    }

    /**
     * Regression: when scrobbles is present, all-time stats must aggregate
     * scrobbles (not annotation.play_count). Otherwise a Last.fm import
     * that adds rows to scrobbles never updates the all-time totals shown
     * on /stats.
     */
    public function testAllTimePrefersScrobblesOverAnnotation(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'A', 'Artist X');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-2', 'B', 'Artist Y');
        // annotation says 5 + 5 = 10 plays; scrobbles tells the truer story
        NavidromeFixtureFactory::insertAnnotation($conn, 'user-1', 'mf-1', 5, '2024-01-01 00:00:00');
        NavidromeFixtureFactory::insertAnnotation($conn, 'user-1', 'mf-2', 5, '2024-01-01 00:00:00');
        for ($i = 0; $i < 12; $i++) {
            NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', date('Y-m-d H:i:s', strtotime("-{$i} day")));
        }
        for ($i = 0; $i < 3; $i++) {
            NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-2', date('Y-m-d H:i:s', strtotime("-{$i} day")));
        }

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $this->assertSame(15, $repo->getTotalPlays(null, null), 'all-time count must come from scrobbles, not annotation');
        $this->assertSame(2, $repo->getDistinctTracksPlayed(null, null));

        $artists = $repo->getTopArtists(null, null, 5);
        $this->assertSame(
            [
                ['artist' => 'Artist X', 'plays' => 12],
                ['artist' => 'Artist Y', 'plays' => 3],
            ],
            $artists,
        );

        $tracks = $repo->getTopTracksWithDetails(null, null, 5);
        $this->assertCount(2, $tracks);
        $this->assertSame('mf-1', $tracks[0]['id']);
        $this->assertSame(12, $tracks[0]['plays']);
        $this->assertSame(3, $tracks[1]['plays']);
    }

    public function testTopArtistsTimelineExposesArtistIdForCovers(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        // The fixture sets media_file.artist_id = 'artist-' . md5($artist).
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Song A', 'Daft Punk');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-2', 'Song B', 'Aphex Twin');
        for ($i = 0; $i < 5; $i++) {
            NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', date('Y-m-d H:i:s', strtotime("-{$i} day - 1 hour")));
        }
        for ($i = 0; $i < 2; $i++) {
            NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-2', date('Y-m-d H:i:s', strtotime("-{$i} day - 2 hour")));
        }

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $top = $repo->getTopArtistsTimeline(12, 5);

        $this->assertCount(2, $top);
        $this->assertSame('Daft Punk', $top[0]['artist']);
        $this->assertSame('artist-' . md5('Daft Punk'), $top[0]['artist_id']);
        $this->assertSame(5, $top[0]['total']);
        $this->assertNotEmpty($top[0]['series']);

        $this->assertSame('Aphex Twin', $top[1]['artist']);
        $this->assertSame('artist-' . md5('Aphex Twin'), $top[1]['artist_id']);
        $this->assertSame(2, $top[1]['total']);
    }
}
