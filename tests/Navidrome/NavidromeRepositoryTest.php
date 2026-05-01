<?php

namespace App\Tests\Navidrome;

use App\Navidrome\NavidromeRepository;
use PHPUnit\Framework\TestCase;

class NavidromeRepositoryTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/navidrome-test-' . uniqid() . '.db';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testTopWithScrobblesAggregatesByCount(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);

        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Hot Track');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-2', 'Mid Track');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-3', 'Cold Track');

        // mf-1 played 5 times in last week
        for ($i = 0; $i < 5; $i++) {
            NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', date('Y-m-d H:i:s', strtotime("-{$i} day")));
        }
        // mf-2 played 2 times
        for ($i = 0; $i < 2; $i++) {
            NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-2', date('Y-m-d H:i:s', strtotime("-{$i} day")));
        }
        // mf-3 played 100 times but a year ago
        for ($i = 0; $i < 100; $i++) {
            NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-3', date('Y-m-d H:i:s', strtotime('-400 day')));
        }

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $this->assertTrue($repo->hasScrobblesTable());

        $now = new \DateTimeImmutable();
        $weekAgo = $now->modify('-7 day');
        $top = $repo->topTracksInWindow($weekAgo, $now, 10);

        $this->assertSame(['mf-1', 'mf-2'], $top, 'Only tracks in the window must appear, ordered by play count.');
    }

    public function testFallbackToAnnotationWhenNoScrobbles(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: false);

        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Hot Track');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-2', 'Mid Track');
        NavidromeFixtureFactory::insertAnnotation($conn, 'user-1', 'mf-1', 5, date('Y-m-d H:i:s', strtotime('-1 day')));
        NavidromeFixtureFactory::insertAnnotation($conn, 'user-1', 'mf-2', 10, date('Y-m-d H:i:s', strtotime('-200 day'))); // out of window

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $this->assertFalse($repo->hasScrobblesTable());

        $top = $repo->topTracksInWindow((new \DateTimeImmutable())->modify('-30 day'), new \DateTimeImmutable(), 10);
        $this->assertSame(['mf-1'], $top);
    }

    public function testNeverPlayedRandom(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath);

        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Played');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-2', 'Unknown 1');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-3', 'Unknown 2');
        NavidromeFixtureFactory::insertAnnotation($conn, 'user-1', 'mf-1', 3, date('Y-m-d H:i:s'));

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $result = $repo->neverPlayedRandom(10);

        sort($result);
        $this->assertSame(['mf-2', 'mf-3'], $result);
    }

    public function testResolveUserIdMissingThrows(): void
    {
        NavidromeFixtureFactory::createDatabase($this->dbPath);
        $repo = new NavidromeRepository($this->dbPath, 'unknown-user');
        $this->expectException(\RuntimeException::class);
        $repo->resolveUserId();
    }

    public function testSummarizePreservesOrder(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath);
        NavidromeFixtureFactory::insertTrack($conn, 'a', 'Alpha');
        NavidromeFixtureFactory::insertTrack($conn, 'b', 'Beta');
        NavidromeFixtureFactory::insertTrack($conn, 'c', 'Charlie');

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $tracks = $repo->summarize(['c', 'a', 'b']);
        $this->assertSame(['Charlie', 'Alpha', 'Beta'], array_map(fn ($t) => $t->title, $tracks));
    }

    public function testFindArtistIdByNameUsesMediaFile(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Track', 'Daft Punk');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-2', 'Other Track', 'Daft Punk');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-3', 'Foo', 'Aphex Twin');

        $repo = new NavidromeRepository($this->dbPath, 'admin');

        $this->assertSame('artist-' . md5('Daft Punk'), $repo->findArtistIdByName('Daft Punk'));
        $this->assertSame('artist-' . md5('Daft Punk'), $repo->findArtistIdByName('  daft punk  '));
        $this->assertSame('artist-' . md5('Aphex Twin'), $repo->findArtistIdByName('Aphex Twin'));
        $this->assertNull($repo->findArtistIdByName('Unknown Artist'));
    }
}
