<?php

namespace App\Tests\LastFm;

use App\LastFm\LastFmImporter;
use App\LastFm\LastFmScrobble;
use App\Navidrome\NavidromeRepository;
use App\Tests\Navidrome\NavidromeFixtureFactory;
use PHPUnit\Framework\TestCase;

class LastFmImporterTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/navidrome-import-' . uniqid() . '.db';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testImportMatchesArtistTitleAndDedupes(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'One More Time', 'Daft Punk');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-2', 'Xtal', 'Aphex Twin');

        // Pre-existing scrobble: should be detected as duplicate.
        $existingTime = new \DateTimeImmutable('2024-06-01 12:00:00');
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', $existingTime->format('Y-m-d H:i:s'));

        $client = new FakeLastFmClient([
            // Should match mf-1, but ±60s of existingTime → duplicate.
            new LastFmScrobble('Daft Punk', 'One More Time', 'Discovery', null, $existingTime->modify('+30 seconds')),
            // Different time, should be inserted.
            new LastFmScrobble('Daft Punk', 'One More Time', 'Discovery', null, new \DateTimeImmutable('2024-07-15 09:00:00')),
            // Should match mf-2 (case-insensitive).
            new LastFmScrobble('aphex twin', 'XTAL', '', null, new \DateTimeImmutable('2024-08-20 10:00:00')),
            // Should NOT match anything.
            new LastFmScrobble('Unknown Artist', 'Unknown Track', '', null, new \DateTimeImmutable('2024-09-01 10:00:00')),
        ]);

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $importer = new LastFmImporter($client, $repo);
        $report = $importer->import('fake-key', 'fake-user');

        $this->assertSame(4, $report->fetched);
        $this->assertSame(2, $report->inserted);
        $this->assertSame(1, $report->duplicates);
        $this->assertSame(1, $report->unmatched);
        $this->assertSame(['Unknown Artist', 'Unknown Track', '', 1], [
            $report->unmatchedRanking()[0]['artist'],
            $report->unmatchedRanking()[0]['title'],
            $report->unmatchedRanking()[0]['album'],
            $report->unmatchedRanking()[0]['count'],
        ]);

        // Verify rows were actually inserted.
        $count = $conn->fetchOne('SELECT COUNT(*) FROM scrobbles WHERE user_id = ?', ['user-1']);
        $this->assertSame(3, (int) $count, '1 pre-existing + 2 inserted = 3 total');
    }

    public function testDryRunDoesNotWrite(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Track', 'Artist');

        $client = new FakeLastFmClient([
            new LastFmScrobble('Artist', 'Track', '', null, new \DateTimeImmutable('2024-06-01 12:00:00')),
        ]);

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $report = (new LastFmImporter($client, $repo))->import('k', 'u', dryRun: true);

        $this->assertSame(1, $report->inserted, 'Report counts inserted even in dry-run');
        $count = $conn->fetchOne('SELECT COUNT(*) FROM scrobbles WHERE user_id = ?', ['user-1']);
        $this->assertSame(0, (int) $count, 'No row should have been written in dry-run mode');
    }
}
