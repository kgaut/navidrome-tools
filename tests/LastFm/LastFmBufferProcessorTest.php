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

    public function testCrashOnFirstBatchRollsBackEverything(): void
    {
        $ndConn = NavidromeFixtureFactory::createDatabase($this->navidromeDbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($ndConn, 'mf-1', 'Hit', 'Artist');
        NavidromeFixtureFactory::insertTrack($ndConn, 'mf-2', 'Other', 'Artist');

        $rows = [
            $this->makeBuffered('Artist', 'Hit', new \DateTimeImmutable('2026-04-02 10:00:00')),
            $this->makeBuffered('Artist', 'Other', new \DateTimeImmutable('2026-04-02 10:01:00')),
        ];
        $this->seedBuffer($rows);

        // Crashing repo fails on the 1st INSERT — entire (and only) batch rollbacks.
        $crashingRepo = $this->makeCrashingRepo(crashAfter: 1);

        // Track persist() calls on the mocked EM to assert that no audit was
        // flushed for the rolled-back batch.
        $persistCount = 0;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->bufferConn);
        $em->method('flush');
        $em->method('persist')->willReturnCallback(function () use (&$persistCount): void {
            $persistCount++;
        });

        $processor = new LastFmBufferProcessor(
            $this->makeStreamingRepo($rows),
            new ScrobbleMatcher(new NavidromeRepository($this->navidromeDbPath, 'admin')),
            $crashingRepo,
            $em,
        );

        $thrown = null;
        try {
            $processor->process(
                auditRun: new RunHistory(RunHistory::TYPE_LASTFM_PROCESS, 'buffer', 'Crash test'),
            );
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(\RuntimeException::class, $thrown);
        $this->assertSame('simulated crash mid-batch', $thrown->getMessage());

        // Navidrome must be empty — the rollback discarded the only INSERT
        // that did make it before the crashing one.
        $count = (int) $ndConn->fetchOne('SELECT COUNT(*) FROM scrobbles');
        $this->assertSame(0, $count, 'Rolled-back batch must leave 0 scrobbles in Navidrome');

        // Buffer rows must still be there for a retry.
        $buffered = (int) $this->bufferConn->fetchOne('SELECT COUNT(*) FROM lastfm_import_buffer');
        $this->assertSame(2, $buffered, 'Buffer rows must survive a rolled-back batch');

        // No audit was persisted for the failed batch.
        $this->assertSame(0, $persistCount, 'Audit rows must NOT be persisted when the batch rolls back');
    }

    public function testCrashOnSecondBatchKeepsFirstBatchCommitted(): void
    {
        $ndConn = NavidromeFixtureFactory::createDatabase($this->navidromeDbPath, withScrobbles: true);

        // 250 unique tracks + buffer rows, all matchable. With BATCH_SIZE=100
        // we have batches at [1-100], [101-200], [201-250]. We crash on the
        // 150th INSERT (= 50th of the 2nd batch) — batch 1 must already be
        // committed, batch 2 fully rolled back, batch 3 never reached.
        $rows = [];
        for ($i = 1; $i <= 250; $i++) {
            NavidromeFixtureFactory::insertTrack($ndConn, sprintf('mf-%03d', $i), sprintf('Track %03d', $i), 'Artist');
            $rows[] = $this->makeBuffered(
                'Artist',
                sprintf('Track %03d', $i),
                // played_at must be unique per (user, played_at, artist, title) — spread
                // by minute to fly under the dedup tolerance + buffer UNIQUE constraint.
                (new \DateTimeImmutable('2026-04-01 00:00:00'))->modify('+' . $i . ' minutes'),
            );
        }
        $this->seedBuffer($rows);

        $crashingRepo = $this->makeCrashingRepo(crashAfter: 150);

        $persistCount = 0;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->bufferConn);
        $em->method('flush');
        $em->method('persist')->willReturnCallback(function () use (&$persistCount): void {
            $persistCount++;
        });

        $processor = new LastFmBufferProcessor(
            $this->makeStreamingRepo($rows),
            new ScrobbleMatcher(new NavidromeRepository($this->navidromeDbPath, 'admin')),
            $crashingRepo,
            $em,
        );

        $thrown = null;
        try {
            $processor->process(
                auditRun: new RunHistory(RunHistory::TYPE_LASTFM_PROCESS, 'buffer', 'Crash test'),
            );
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(\RuntimeException::class, $thrown);

        // Batch 1 (rows 1-100) committed → 100 scrobbles + 100 buffer rows
        // deleted + 100 audits persisted.
        $count = (int) $ndConn->fetchOne('SELECT COUNT(*) FROM scrobbles');
        $this->assertSame(100, $count, 'First batch must remain committed');

        $buffered = (int) $this->bufferConn->fetchOne('SELECT COUNT(*) FROM lastfm_import_buffer');
        $this->assertSame(150, $buffered, 'Rows 101-250 must still be in the buffer for retry');

        $this->assertSame(100, $persistCount, 'Only the first batch must have flushed audit rows');
    }

    public function testReprocessingAfterPartialFailureIsIdempotent(): void
    {
        // Replay the second-batch-crash scenario but with a smaller dataset
        // so the fixture is cheap : 3 rows, BATCH_SIZE matters less here, the
        // important property is that on a clean re-run the leftover rows are
        // marked DUPLICATE not re-inserted (proving dedup catches what made
        // it through committed batches).
        $ndConn = NavidromeFixtureFactory::createDatabase($this->navidromeDbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($ndConn, 'mf-1', 'Track 1', 'Artist');
        NavidromeFixtureFactory::insertTrack($ndConn, 'mf-2', 'Track 2', 'Artist');

        // Pre-existing scrobble at +30s relative to row 1 played_at — within
        // tolerance, so re-processing row 1 must be marked duplicate.
        $row1Time = new \DateTimeImmutable('2026-04-01 12:00:00');
        NavidromeFixtureFactory::insertScrobble(
            $ndConn,
            'user-1',
            'mf-1',
            $row1Time->modify('+30 seconds')->format('Y-m-d H:i:s'),
        );

        $rows = [
            $this->makeBuffered('Artist', 'Track 1', $row1Time),
            $this->makeBuffered('Artist', 'Track 2', new \DateTimeImmutable('2026-04-02 12:00:00')),
        ];
        $this->seedBuffer($rows);

        // Clean run, no crash — the dedup must mark row 1 duplicate, row 2
        // inserted. This is the « after partial failure » re-processing
        // outcome.
        $processor = $this->makeProcessor($rows);
        $report = $processor->process(
            auditRun: new RunHistory(RunHistory::TYPE_LASTFM_PROCESS, 'buffer', 'Replay test'),
        );

        $this->assertSame(1, $report->duplicates);
        $this->assertSame(1, $report->inserted);
        // Total scrobbles = pre-existing (1) + freshly inserted row 2 (1).
        $this->assertSame(2, (int) $ndConn->fetchOne('SELECT COUNT(*) FROM scrobbles'));
    }

    private function makeCrashingRepo(int $crashAfter): NavidromeRepository
    {
        return new class ($this->navidromeDbPath, 'admin', $crashAfter) extends NavidromeRepository {
            public int $insertCalls = 0;

            public function __construct(string $dbPath, string $userName, private int $crashOn)
            {
                parent::__construct($dbPath, $userName);
            }

            public function insertScrobble(string $userId, string $mediaFileId, \DateTimeInterface $time): void
            {
                $this->insertCalls++;
                if ($this->insertCalls === $this->crashOn) {
                    throw new \RuntimeException('simulated crash mid-batch');
                }
                parent::insertScrobble($userId, $mediaFileId, $time);
            }
        };
    }

    /**
     * @param list<LastFmBufferedScrobble> $rows
     */
    private function makeStreamingRepo(array $rows): LastFmBufferedScrobbleRepository
    {
        $repo = $this->createMock(LastFmBufferedScrobbleRepository::class);
        $repo->method('streamAll')->willReturnCallback(
            function (int $limit = 0) use ($rows): \Generator {
                $emitted = 0;
                foreach ($rows as $row) {
                    if ($limit > 0 && $emitted >= $limit) {
                        break;
                    }
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

        return $repo;
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
