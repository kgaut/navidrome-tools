<?php

namespace App\Tests\LastFm;

use App\LastFm\LastFmFetcher;
use App\LastFm\LastFmScrobble;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

class LastFmFetcherTest extends TestCase
{
    private string $bufferDbPath;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->bufferDbPath = sys_get_temp_dir() . '/lastfm-fetcher-' . uniqid() . '.db';
        $this->conn = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $this->bufferDbPath,
        ]);
        // Mirror the migration schema for lastfm_import_buffer.
        $this->conn->executeStatement(<<<'SQL'
            CREATE TABLE lastfm_import_buffer (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                lastfm_user VARCHAR(255) NOT NULL,
                artist VARCHAR(255) NOT NULL,
                title VARCHAR(255) NOT NULL,
                album VARCHAR(255) DEFAULT NULL,
                mbid VARCHAR(64) DEFAULT NULL,
                played_at DATETIME NOT NULL,
                fetched_at DATETIME NOT NULL,
                UNIQUE (lastfm_user, played_at, artist, title)
            )
        SQL);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->bufferDbPath)) {
            unlink($this->bufferDbPath);
        }
    }

    public function testFetchInsertsScrobblesIntoBuffer(): void
    {
        $client = new FakeLastFmClient([
            new LastFmScrobble('Artist A', 'Title A', 'Album A', null, new \DateTimeImmutable('2026-04-01 10:00:00', new \DateTimeZone('UTC'))),
            new LastFmScrobble('Artist B', 'Title B', '', 'mbid-b', new \DateTimeImmutable('2026-04-01 11:00:00', new \DateTimeZone('UTC'))),
        ]);

        $fetcher = new LastFmFetcher($client, $this->conn);
        $report = $fetcher->fetch('key', 'alice');

        $this->assertSame(2, $report->fetched);
        $this->assertSame(2, $report->buffered);
        $this->assertSame(0, $report->alreadyBuffered);

        $rows = $this->conn->fetchAllAssociative('SELECT * FROM lastfm_import_buffer ORDER BY played_at');
        $this->assertCount(2, $rows);
        $this->assertSame('Artist A', $rows[0]['artist']);
        $this->assertSame('Album A', $rows[0]['album']);
        $this->assertNull($rows[0]['mbid']);
        $this->assertSame('Artist B', $rows[1]['artist']);
        $this->assertNull($rows[1]['album'], 'Empty album should land as NULL');
        $this->assertSame('mbid-b', $rows[1]['mbid']);
        $this->assertSame('alice', $rows[0]['lastfm_user']);
    }

    public function testFetchIsIdempotentAcrossRuns(): void
    {
        $scrobble = new LastFmScrobble(
            'Artist A',
            'Title A',
            'Album A',
            null,
            new \DateTimeImmutable('2026-04-01 10:00:00', new \DateTimeZone('UTC')),
        );
        $client = new FakeLastFmClient([$scrobble]);
        $fetcher = new LastFmFetcher($client, $this->conn);

        $first = $fetcher->fetch('key', 'alice');
        $this->assertSame(1, $first->buffered);
        $this->assertSame(0, $first->alreadyBuffered);

        // Re-fetching the same window must rely on the unique constraint to
        // skip the duplicate without raising.
        $second = $fetcher->fetch('key', 'alice');
        $this->assertSame(1, $second->fetched);
        $this->assertSame(0, $second->buffered);
        $this->assertSame(1, $second->alreadyBuffered);

        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM lastfm_import_buffer');
        $this->assertSame(1, $count);
    }

    public function testDryRunDoesNotInsert(): void
    {
        $client = new FakeLastFmClient([
            new LastFmScrobble('A', 'T', '', null, new \DateTimeImmutable('2026-04-01 10:00:00', new \DateTimeZone('UTC'))),
        ]);
        $fetcher = new LastFmFetcher($client, $this->conn);

        $report = $fetcher->fetch('key', 'alice', dryRun: true);
        $this->assertSame(1, $report->fetched);
        $this->assertSame(0, $report->buffered);

        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM lastfm_import_buffer');
        $this->assertSame(0, $count);
    }

    public function testMaxScrobblesCapsTheStream(): void
    {
        $client = new FakeLastFmClient([
            new LastFmScrobble('A', 'T1', '', null, new \DateTimeImmutable('2026-04-01 10:00:00', new \DateTimeZone('UTC'))),
            new LastFmScrobble('A', 'T2', '', null, new \DateTimeImmutable('2026-04-01 11:00:00', new \DateTimeZone('UTC'))),
            new LastFmScrobble('A', 'T3', '', null, new \DateTimeImmutable('2026-04-01 12:00:00', new \DateTimeZone('UTC'))),
        ]);
        $fetcher = new LastFmFetcher($client, $this->conn);

        $report = $fetcher->fetch('key', 'alice', maxScrobbles: 2);
        $this->assertSame(2, $report->fetched);
        $this->assertSame(2, $report->buffered);
    }

    public function testDifferentUsersDoNotCollide(): void
    {
        // Same artist/title/playedAt for two different lastfm users — both
        // rows should land thanks to the unique constraint covering
        // lastfm_user.
        $playedAt = new \DateTimeImmutable('2026-04-01 10:00:00', new \DateTimeZone('UTC'));
        $client1 = new FakeLastFmClient([new LastFmScrobble('A', 'T', '', null, $playedAt)]);
        $client2 = new FakeLastFmClient([new LastFmScrobble('A', 'T', '', null, $playedAt)]);

        (new LastFmFetcher($client1, $this->conn))->fetch('key', 'alice');
        $report = (new LastFmFetcher($client2, $this->conn))->fetch('key', 'bob');

        $this->assertSame(1, $report->buffered);
        $count = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM lastfm_import_buffer');
        $this->assertSame(2, $count);
    }
}
