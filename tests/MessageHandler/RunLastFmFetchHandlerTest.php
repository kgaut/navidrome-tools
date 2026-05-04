<?php

namespace App\Tests\MessageHandler;

use App\Entity\RunHistory;
use App\LastFm\LastFmFetcher;
use App\LastFm\LastFmScrobble;
use App\Message\RunLastFmFetchMessage;
use App\MessageHandler\RunLastFmFetchHandler;
use App\Repository\RunHistoryRepository;
use App\Service\RunHistoryRecorder;
use App\Tests\LastFm\FakeLastFmClient;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class RunLastFmFetchHandlerTest extends TestCase
{
    private string $bufferDbPath;
    private Connection $conn;

    protected function setUp(): void
    {
        $this->bufferDbPath = sys_get_temp_dir() . '/lastfm-fetch-handler-' . uniqid() . '.db';
        $this->conn = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $this->bufferDbPath,
        ]);
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

    public function testHandlerWiresProgressCallbackAndPersistsMetricsOnFinalEntry(): void
    {
        $entry = new RunHistory(RunHistory::TYPE_LASTFM_FETCH, 'alice', 'Last.fm fetch — alice');
        $entry->setStatus(RunHistory::STATUS_QUEUED);
        $this->forceId($entry, 42);

        $repo = $this->createMock(RunHistoryRepository::class);
        $repo->method('find')->with(42)->willReturn($entry);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('flush');

        $client = new FakeLastFmClient($this->makeScrobbles(120));
        $fetcher = new LastFmFetcher($client, $this->conn);

        $recorder = new RunHistoryRecorder($em);
        $handler = new RunLastFmFetchHandler($repo, $recorder, $fetcher);

        ($handler)(new RunLastFmFetchMessage(
            runHistoryId: 42,
            apiKey: 'key',
            lastFmUser: 'alice',
            maxScrobbles: 120,
        ));

        $this->assertSame(RunHistory::STATUS_SUCCESS, $entry->getStatus());
        $metrics = $entry->getMetrics();
        $this->assertNotNull($metrics);
        $this->assertSame(120, $metrics['fetched']);
        $this->assertSame(120, $metrics['buffered']);
        $this->assertSame(0, $metrics['already_buffered']);

        // Progress was emitted at least once (every 50 items + final tick).
        $progress = $entry->getProgress();
        $this->assertNotNull($progress);
        $this->assertSame(120, $progress['current']);
        $this->assertSame(120, $progress['total']);
        $this->assertSame(100.0, $progress['percent']);
    }

    /**
     * @return list<LastFmScrobble>
     */
    private function makeScrobbles(int $count): array
    {
        $scrobbles = [];
        for ($i = 0; $i < $count; $i++) {
            $scrobbles[] = new LastFmScrobble(
                artist: 'Artist',
                title: 'Track ' . $i,
                album: 'Album',
                mbid: null,
                playedAt: (new \DateTimeImmutable('2026-04-01 10:00:00', new \DateTimeZone('UTC')))
                    ->modify('+' . $i . ' minute'),
            );
        }
        return $scrobbles;
    }

    private function forceId(RunHistory $entry, int $id): void
    {
        $reflection = new \ReflectionClass($entry);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($entry, $id);
    }
}
