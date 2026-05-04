<?php

namespace App\Tests\LastFm;

use App\Entity\LastFmBufferedScrobble;
use App\Entity\LastFmImportTrack;
use App\Entity\RunHistory;
use App\LastFm\LastFmBufferProcessor;
use App\LastFm\ScrobbleMatcher;
use App\Navidrome\NavidromeRepository;
use App\Repository\LastFmBufferedScrobbleRepository;
use App\Tests\Navidrome\NavidromeFixtureFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class LastFmBufferProcessorTest extends TestCase
{
    private string $navidromeDbPath;
    private string $bufferDbPath;
    private Connection $bufferConn;

    protected function setUp(): void
    {
        $this->navidromeDbPath = sys_get_temp_dir() . '/buffer-proc-nd-' . uniqid() . '.db';
        $this->bufferDbPath = sys_get_temp_dir() . '/buffer-proc-buf-' . uniqid() . '.db';
        $this->bufferConn = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $this->bufferDbPath,
        ]);
        $this->bufferConn->executeStatement(<<<'SQL'
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
        foreach ([$this->navidromeDbPath, $this->bufferDbPath] as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    public function testProcessRoutesEachBufferedScrobbleToTheRightStatus(): void
    {
        $ndConn = NavidromeFixtureFactory::createDatabase($this->navidromeDbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($ndConn, 'mf-1', 'Hit', 'Artist');
        NavidromeFixtureFactory::insertTrack($ndConn, 'mf-2', 'Other', 'Artist');
        // Pre-existing scrobble at noon → second within 60s = duplicate.
        $existing = new \DateTimeImmutable('2026-04-01 12:00:00');
        NavidromeFixtureFactory::insertScrobble($ndConn, 'user-1', 'mf-1', $existing->format('Y-m-d H:i:s'));

        $rows = [
            // Will match mf-1 + ±60s of pre-existing → duplicate.
            $this->makeBuffered('Artist', 'Hit', $existing->modify('+10 seconds')),
            // Clean match → inserted.
            $this->makeBuffered('Artist', 'Other', new \DateTimeImmutable('2026-04-02 10:00:00')),
            // No matching track → unmatched.
            $this->makeBuffered('Nope', 'Nada', new \DateTimeImmutable('2026-04-03 10:00:00')),
        ];
        $this->seedBuffer($rows);

        $processor = $this->makeProcessor($rows);
        $auditRun = new RunHistory(RunHistory::TYPE_LASTFM_PROCESS, 'buffer', 'Process buffer test');
        $report = $processor->process(auditRun: $auditRun);

        $this->assertSame(3, $report->considered);
        $this->assertSame(1, $report->inserted);
        $this->assertSame(1, $report->duplicates);
        $this->assertSame(1, $report->unmatched);
        $this->assertSame(0, $report->skipped);

        // Navidrome should now have the pre-existing scrobble plus the newly
        // inserted one (mf-2). The duplicate row was NOT re-inserted.
        $count = (int) $ndConn->fetchOne('SELECT COUNT(*) FROM scrobbles WHERE user_id = ?', ['user-1']);
        $this->assertSame(2, $count);

        // Buffer rows should have been deleted (regardless of outcome).
        $remaining = (int) $this->bufferConn->fetchOne('SELECT COUNT(*) FROM lastfm_import_buffer');
        $this->assertSame(0, $remaining, 'All processed rows should be removed from the buffer');
    }

    public function testDryRunLeavesBufferAndNavidromeUntouched(): void
    {
        $ndConn = NavidromeFixtureFactory::createDatabase($this->navidromeDbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($ndConn, 'mf-1', 'Hit', 'Artist');

        $rows = [
            $this->makeBuffered('Artist', 'Hit', new \DateTimeImmutable('2026-04-02 10:00:00')),
        ];
        $this->seedBuffer($rows);

        $processor = $this->makeProcessor($rows);
        $report = $processor->process(dryRun: true);

        $this->assertSame(1, $report->inserted);
        $this->assertSame(0, (int) $ndConn->fetchOne('SELECT COUNT(*) FROM scrobbles'));
        $this->assertSame(1, (int) $this->bufferConn->fetchOne('SELECT COUNT(*) FROM lastfm_import_buffer'));
    }

    public function testProcessRespectsLimit(): void
    {
        $ndConn = NavidromeFixtureFactory::createDatabase($this->navidromeDbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($ndConn, 'mf-1', 'Hit', 'Artist');

        $rows = [
            $this->makeBuffered('Artist', 'Hit', new \DateTimeImmutable('2026-04-01 10:00:00')),
            $this->makeBuffered('Artist', 'Hit', new \DateTimeImmutable('2026-04-02 10:00:00')),
            $this->makeBuffered('Artist', 'Hit', new \DateTimeImmutable('2026-04-03 10:00:00')),
        ];
        $this->seedBuffer($rows);

        $processor = $this->makeProcessor($rows);
        $report = $processor->process(limit: 2);

        $this->assertSame(2, $report->considered);
    }

    /**
     * @param list<LastFmBufferedScrobble> $rows
     */
    private function makeProcessor(array $rows): LastFmBufferProcessor
    {
        $repo = $this->createMock(LastFmBufferedScrobbleRepository::class);
        $repo->method('streamAll')->willReturnCallback(
            function (int $limit = 0) use ($rows): \Generator {
                $emitted = 0;
                foreach ($rows as $row) {
                    if ($limit > 0 && $emitted >= $limit) {
                        break;
                    }
                    // Skip rows already deleted from the buffer (id-not-found).
                    $stillThere = (int) $this->bufferConn->fetchOne(
                        'SELECT COUNT(*) FROM lastfm_import_buffer WHERE id = ?',
                        [$row->getId()],
                    );
                    if ($stillThere === 0) {
                        continue;
                    }
                    $emitted++;
                    yield $row;
                }
            }
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->bufferConn);
        $em->method('flush');
        $em->method('persist');

        $matcher = new ScrobbleMatcher(
            new NavidromeRepository($this->navidromeDbPath, 'admin'),
        );

        return new LastFmBufferProcessor(
            $repo,
            $matcher,
            new NavidromeRepository($this->navidromeDbPath, 'admin'),
            $em,
        );
    }

    /**
     * @param list<LastFmBufferedScrobble> $rows
     */
    private function seedBuffer(array $rows): void
    {
        foreach ($rows as $row) {
            $this->bufferConn->executeStatement(
                'INSERT INTO lastfm_import_buffer (id, lastfm_user, artist, title, album, mbid, played_at, fetched_at) '
                . 'VALUES (:id, :user, :artist, :title, :album, :mbid, :played, :fetched)',
                [
                    'id' => $row->getId(),
                    'user' => $row->getLastfmUser(),
                    'artist' => $row->getArtist(),
                    'title' => $row->getTitle(),
                    'album' => $row->getAlbum(),
                    'mbid' => $row->getMbid(),
                    'played' => $row->getPlayedAt()->format('Y-m-d H:i:s'),
                    'fetched' => $row->getFetchedAt()->format('Y-m-d H:i:s'),
                ],
            );
        }
    }

    private function makeBuffered(string $artist, string $title, \DateTimeImmutable $playedAt): LastFmBufferedScrobble
    {
        static $next = 1;
        $row = new LastFmBufferedScrobble(
            lastfmUser: 'alice',
            artist: $artist,
            title: $title,
            album: null,
            mbid: null,
            playedAt: $playedAt,
            fetchedAt: new \DateTimeImmutable('2026-05-04 00:00:00'),
        );
        // The entity does not expose a setter for id (Doctrine assigns it via
        // GeneratedValue). Tests need a stable id to drive the seed/delete
        // round-trip — assign via reflection.
        $ref = new \ReflectionProperty(LastFmBufferedScrobble::class, 'id');
        $ref->setValue($row, $next++);

        return $row;
    }
}
