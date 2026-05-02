<?php

namespace App\Tests\LastFm;

use App\LastFm\LastFmLovedTrack;
use App\LastFm\LovedStarredSyncService;
use App\Navidrome\NavidromeRepository;
use App\Tests\Navidrome\NavidromeFixtureFactory;
use App\Tests\Subsonic\FakeSubsonicClient;
use PHPUnit\Framework\TestCase;

class LovedStarredSyncServiceTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/navidrome-sync-' . uniqid() . '.db';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testSyncStarsLovedTracksMatchingByMbidOrCouple(): void
    {
        NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: false);
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: false);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-by-couple', 'Halo', 'Beyoncé');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-by-name-only', 'Phantom of the Opera', 'Iron Maiden');

        $loved = [
            new LastFmLovedTrack('Beyoncé', 'Halo', null, new \DateTimeImmutable('2024-01-01')),
            new LastFmLovedTrack('Iron Maiden', 'Phantom of the Opera', null, new \DateTimeImmutable('2024-01-02')),
            new LastFmLovedTrack('Unknown Artist', 'Unknown Title', null, new \DateTimeImmutable('2024-01-03')),
        ];

        $client = new FakeLastFmClient([], $loved);
        $sub = new FakeSubsonicClient([]);
        $sync = $this->makeService($client, $sub);

        $report = $sync->sync(LovedStarredSyncService::DIRECTION_LF_TO_ND, dryRun: false);

        $this->assertSame(3, $report->lovedCount);
        $this->assertSame(0, $report->starredCount);
        $this->assertCount(2, $report->starredAdded);
        $this->assertCount(1, $report->lovedUnmatched);
        $this->assertSame('Unknown Artist', $report->lovedUnmatched[0]['artist']);
        $this->assertSame(['mf-by-couple', 'mf-by-name-only'], $sub->starCalls);
    }

    public function testSyncLovesStarredTracksOnLastFm(): void
    {
        NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: false);
        $sub = new FakeSubsonicClient([
            ['id' => 'mf-1', 'title' => 'Halo', 'artist' => 'Beyoncé', 'album' => 'I Am'],
            ['id' => 'mf-2', 'title' => 'Smile', 'artist' => 'Lily Allen', 'album' => 'Alright Still'],
        ]);

        // Last.fm only loves "Halo" → "Smile" must be propagated nd → lf.
        $client = new FakeLastFmClient([], [
            new LastFmLovedTrack('Beyoncé', 'Halo', null, new \DateTimeImmutable('2024-01-01')),
        ]);

        $report = $this->makeService($client, $sub)->sync(
            LovedStarredSyncService::DIRECTION_ND_TO_LF,
            dryRun: false,
        );

        $this->assertCount(1, $report->lovedAdded);
        $this->assertSame('Smile', $report->lovedAdded[0]['title']);
        $this->assertCount(1, $client->writeActions);
        $this->assertSame(['action' => 'love', 'artist' => 'Lily Allen', 'title' => 'Smile'], $client->writeActions[0]);
    }

    public function testSyncBothDirectionsIsIdempotent(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: false);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Halo', 'Beyoncé');

        $sub = new FakeSubsonicClient([
            ['id' => 'mf-1', 'title' => 'Halo', 'artist' => 'Beyoncé', 'album' => 'I Am'],
        ]);
        $client = new FakeLastFmClient([], [
            new LastFmLovedTrack('Beyoncé', 'Halo', null, new \DateTimeImmutable('2024-01-01')),
        ]);

        $report = $this->makeService($client, $sub)->sync(
            LovedStarredSyncService::DIRECTION_BOTH,
            dryRun: false,
        );

        $this->assertSame(1, $report->commonCount);
        $this->assertSame([], $report->starredAdded);
        $this->assertSame([], $report->lovedAdded);
        $this->assertSame([], $sub->starCalls);
        $this->assertSame([], $client->writeActions);
    }

    public function testSyncDryRunWritesNothing(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: false);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Halo', 'Beyoncé');

        $sub = new FakeSubsonicClient([
            ['id' => 'mf-2', 'title' => 'Smile', 'artist' => 'Lily Allen', 'album' => 'Alright Still'],
        ]);
        $client = new FakeLastFmClient([], [
            new LastFmLovedTrack('Beyoncé', 'Halo', null, new \DateTimeImmutable('2024-01-01')),
        ]);

        $report = $this->makeService($client, $sub)->sync(
            LovedStarredSyncService::DIRECTION_BOTH,
            dryRun: true,
        );

        $this->assertCount(1, $report->starredAdded);
        $this->assertCount(1, $report->lovedAdded);
        $this->assertSame([], $sub->starCalls, 'Dry-run must not call Subsonic.starTracks');
        $this->assertSame([], $client->writeActions, 'Dry-run must not call Last.fm track.love');
    }

    public function testSyncCollectsTrackLoveErrorsWithoutAborting(): void
    {
        NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: false);
        $sub = new FakeSubsonicClient([
            ['id' => 'mf-1', 'title' => 'Halo', 'artist' => 'Beyoncé', 'album' => 'I Am'],
            ['id' => 'mf-2', 'title' => 'Smile', 'artist' => 'Lily Allen', 'album' => 'Alright Still'],
        ]);
        $client = new FakeLastFmClient([], [], writeShouldFail: true);

        $report = $this->makeService($client, $sub)->sync(
            LovedStarredSyncService::DIRECTION_ND_TO_LF,
            dryRun: false,
        );

        $this->assertCount(0, $report->lovedAdded, 'Failed loves must roll back from lovedAdded');
        $this->assertCount(2, $report->errors);
    }

    private function makeService(FakeLastFmClient $client, FakeSubsonicClient $sub): LovedStarredSyncService
    {
        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $aliasRepo = new InMemoryAliasRepository([]);
        $authService = new StubLastFmAuthService();

        return new LovedStarredSyncService($client, $authService, $sub, $repo, $aliasRepo, 'KEY', 'SECRET');
    }
}
