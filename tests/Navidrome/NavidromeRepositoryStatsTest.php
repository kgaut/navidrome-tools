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

    public function testGetIncompleteAlbumsListsAlbumsWithoutMbzAlbumId(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);

        // Album with MBID — should NOT appear
        NavidromeFixtureFactory::insertTrack(
            $conn,
            'mf-1a',
            'Track A',
            'Artist X',
            180,
            'Tagged Album',
            null,
            '',
            null,
            null,
            mbzAlbumId: '00000000-0000-0000-0000-000000000001',
        );
        NavidromeFixtureFactory::insertTrack(
            $conn,
            'mf-1b',
            'Track B',
            'Artist X',
            180,
            'Tagged Album',
            null,
            '',
            null,
            null,
            mbzAlbumId: '00000000-0000-0000-0000-000000000001',
        );

        // Album without MBID, 3 tracks, 5 plays
        NavidromeFixtureFactory::insertTrack($conn, 'mf-2a', 'T1', 'Artist Y', 180, 'Untagged Album');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-2b', 'T2', 'Artist Y', 180, 'Untagged Album');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-2c', 'T3', 'Artist Y', 180, 'Untagged Album');
        for ($i = 0; $i < 3; $i++) {
            NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-2a', '2025-01-0' . ($i + 1) . ' 10:00:00');
        }
        for ($i = 0; $i < 2; $i++) {
            NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-2b', '2025-01-0' . ($i + 1) . ' 11:00:00');
        }

        // Smaller untagged album, 1 track, 1 play (should rank below)
        NavidromeFixtureFactory::insertTrack($conn, 'mf-3', 'Solo', 'Artist Z', 180, 'Tiny Album');
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-3', '2025-01-01 12:00:00');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $albums = $repo->getIncompleteAlbums();

        $this->assertCount(2, $albums, 'Tagged Album excluded');
        $this->assertSame('Untagged Album', $albums[0]['album']);
        $this->assertSame(3, $albums[0]['tracks']);
        $this->assertSame(5, $albums[0]['plays']);
        $this->assertSame('Tiny Album', $albums[1]['album']);
        $this->assertSame(1, $albums[1]['plays']);
    }

    public function testGetForgottenArtistsRanksByPlaysAndIdleness(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'A', 'Old Favorite');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-2', 'B', 'Recent Listen');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-3', 'C', 'Low Plays Old');

        $now = new \DateTimeImmutable();

        // Old Favorite: 60 plays, all 18 months ago → forgotten
        for ($i = 0; $i < 60; $i++) {
            NavidromeFixtureFactory::insertScrobble(
                $conn,
                'user-1',
                'mf-1',
                $now->modify('-18 months')->modify('+' . $i . ' minutes')->format('Y-m-d H:i:s'),
            );
        }
        // Recent Listen: 80 plays, last one yesterday → not forgotten
        for ($i = 0; $i < 80; $i++) {
            NavidromeFixtureFactory::insertScrobble(
                $conn,
                'user-1',
                'mf-2',
                $now->modify('-1 day')->modify('-' . $i . ' minutes')->format('Y-m-d H:i:s'),
            );
        }
        // Low Plays Old: only 10 plays 18 months ago → below min_plays
        for ($i = 0; $i < 10; $i++) {
            NavidromeFixtureFactory::insertScrobble(
                $conn,
                'user-1',
                'mf-3',
                $now->modify('-18 months')->modify('+' . $i . ' hours')->format('Y-m-d H:i:s'),
            );
        }

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $forgotten = $repo->getForgottenArtists(minPlays: 50, idleMonths: 12);

        $this->assertCount(1, $forgotten, 'only Old Favorite passes both thresholds');
        $this->assertSame('Old Favorite', $forgotten[0]['artist']);
        $this->assertSame(60, $forgotten[0]['plays']);
        $this->assertGreaterThan(500, $forgotten[0]['idle_days'], 'about 18 months idle');
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

    public function testGetTopAlbumsAggregatesPlaysAndTrackCount(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        // Album "Discovery" — Daft Punk: 2 tracks, 7 total plays.
        NavidromeFixtureFactory::insertTrack($conn, 'mf-d1', 'One More Time', 'Daft Punk', 180, 'Discovery');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-d2', 'Aerodynamic', 'Daft Punk', 180, 'Discovery');
        // Album "Selected Ambient" — Aphex Twin: 1 track, 4 plays.
        NavidromeFixtureFactory::insertTrack($conn, 'mf-a1', 'Xtal', 'Aphex Twin', 180, 'Selected Ambient');
        // Empty-album track must be ignored.
        NavidromeFixtureFactory::insertTrack($conn, 'mf-e1', 'Loose', 'Random', 180, '');

        for ($i = 0; $i < 4; $i++) {
            NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-d1', date('Y-m-d H:i:s', strtotime("-{$i} day - 1 hour")));
        }
        for ($i = 0; $i < 3; $i++) {
            NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-d2', date('Y-m-d H:i:s', strtotime("-{$i} day - 2 hour")));
        }
        for ($i = 0; $i < 4; $i++) {
            NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-a1', date('Y-m-d H:i:s', strtotime("-{$i} day - 3 hour")));
        }
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-e1', date('Y-m-d H:i:s', strtotime('-1 hour')));

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $albums = $repo->getTopAlbums(null, null, 10);

        $this->assertCount(2, $albums, 'empty album skipped');
        $this->assertSame('Discovery', $albums[0]['album']);
        $this->assertSame('Daft Punk', $albums[0]['album_artist']);
        $this->assertSame(7, $albums[0]['plays']);
        $this->assertSame(2, $albums[0]['track_count']);
        $this->assertSame('mf-d1', $albums[0]['sample_track_id'], 'most-played track in album');

        $this->assertSame('Selected Ambient', $albums[1]['album']);
        $this->assertSame(4, $albums[1]['plays']);
        $this->assertSame(1, $albums[1]['track_count']);
        $this->assertSame('mf-a1', $albums[1]['sample_track_id']);
    }

    public function testGetTopAlbumsRespectsWindowAndClient(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Track A', 'Artist X', 180, 'Album X');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-2', 'Track B', 'Artist Y', 180, 'Album Y');

        // Outside window
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', '2024-01-01 10:00:00', 'Symfonium');
        // Inside window, two clients
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', '2025-06-01 10:00:00', 'Symfonium');
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', '2025-06-02 10:00:00', 'Symfonium');
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-2', '2025-06-03 10:00:00', 'DSub');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $from = new \DateTimeImmutable('2025-01-01 00:00:00');
        $to = new \DateTimeImmutable('2026-01-01 00:00:00');

        $all = $repo->getTopAlbums($from, $to, 10);
        $this->assertCount(2, $all);
        $this->assertSame('Album X', $all[0]['album']);
        $this->assertSame(2, $all[0]['plays']);

        $symf = $repo->getTopAlbums($from, $to, 10, 'Symfonium');
        $this->assertCount(1, $symf);
        $this->assertSame('Album X', $symf[0]['album']);
        $this->assertSame(2, $symf[0]['plays']);
    }

    public function testGetTopAlbumsReturnsEmptyWhenScrobblesMissing(): void
    {
        NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: false);
        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $this->assertSame([], $repo->getTopAlbums(null, null, 10));
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
