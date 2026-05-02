<?php

namespace App\Tests\Service;

use App\Entity\LastFmImportTrack;
use App\Entity\RunHistory;
use App\LastFm\ScrobbleMatcher;
use App\Navidrome\NavidromeRepository;
use App\Repository\LastFmImportTrackRepository;
use App\Service\LastFmRematchService;
use App\Tests\Navidrome\NavidromeFixtureFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class LastFmRematchServiceTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/navidrome-rematch-' . uniqid() . '.db';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testRematchInsertsScrobblesAndFlipsStatus(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        // mf-1 has a scrobble at noon → second one within 60s should be a duplicate.
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Hit', 'Artist');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-2', 'Other Track', 'Artist');
        $existing = new \DateTimeImmutable('2026-04-01 12:00:00');
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-1', $existing->format('Y-m-d H:i:s'));

        $run = $this->makeRun();

        $tracks = [
            // Will match mf-1, but ±60s of existing scrobble → duplicate.
            $this->makeUnmatchedTrack($run, 'Artist', 'Hit', $existing->modify('+10 seconds')),
            // Will match mf-2 cleanly → inserted.
            $this->makeUnmatchedTrack($run, 'Artist', 'Other Track', new \DateTimeImmutable('2026-04-02 10:00:00')),
            // Won't match anything → still unmatched.
            $this->makeUnmatchedTrack($run, 'Nope', 'Nada', new \DateTimeImmutable('2026-04-03 10:00:00')),
        ];

        $service = $this->makeService($tracks);
        $report = $service->rematch();

        $this->assertSame(3, $report->considered);
        $this->assertSame(1, $report->matchedAsInserted);
        $this->assertSame(1, $report->matchedAsDuplicate);
        $this->assertSame(0, $report->skipped);
        $this->assertSame(1, $report->stillUnmatched);

        // Statuses should have been updated.
        $this->assertSame(LastFmImportTrack::STATUS_DUPLICATE, $tracks[0]->getStatus());
        $this->assertSame('mf-1', $tracks[0]->getMatchedMediaFileId());

        $this->assertSame(LastFmImportTrack::STATUS_INSERTED, $tracks[1]->getStatus());
        $this->assertSame('mf-2', $tracks[1]->getMatchedMediaFileId());

        $this->assertSame(LastFmImportTrack::STATUS_UNMATCHED, $tracks[2]->getStatus());
        $this->assertNull($tracks[2]->getMatchedMediaFileId());

        // Navidrome should have the new scrobble.
        $count = $conn->fetchOne('SELECT COUNT(*) FROM scrobbles WHERE user_id = ?', ['user-1']);
        $this->assertSame(2, (int) $count, '1 pre-existing + 1 newly inserted = 2');
    }

    public function testDryRunDoesNotModifyEntitiesOrNavidrome(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Hit', 'Artist');

        $run = $this->makeRun();
        $tracks = [
            $this->makeUnmatchedTrack($run, 'Artist', 'Hit', new \DateTimeImmutable('2026-04-02 10:00:00')),
        ];

        $service = $this->makeService($tracks);
        $report = $service->rematch(dryRun: true);

        $this->assertSame(1, $report->matchedAsInserted);
        // Track entity state must remain unchanged in dry-run.
        $this->assertSame(LastFmImportTrack::STATUS_UNMATCHED, $tracks[0]->getStatus());
        $this->assertNull($tracks[0]->getMatchedMediaFileId());
        // Navidrome should be untouched.
        $count = $conn->fetchOne('SELECT COUNT(*) FROM scrobbles WHERE user_id = ?', ['user-1']);
        $this->assertSame(0, (int) $count);
    }

    public function testRematchIsIdempotent(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Hit', 'Artist');

        $run = $this->makeRun();
        $tracks = [
            $this->makeUnmatchedTrack($run, 'Artist', 'Hit', new \DateTimeImmutable('2026-04-02 10:00:00')),
        ];

        $service = $this->makeService($tracks);
        $first = $service->rematch();
        $this->assertSame(1, $first->matchedAsInserted);
        $this->assertSame(LastFmImportTrack::STATUS_INSERTED, $tracks[0]->getStatus());

        // After the first rematch the row's status moved to inserted, so the
        // second run sees no unmatched rows and is a no-op (the fake repo
        // filters by status to mirror the real query).
        $service2 = $this->makeService($tracks);
        $second = $service2->rematch();
        $this->assertSame(0, $second->considered);

        // Navidrome should still have only one scrobble for that match.
        $count = $conn->fetchOne('SELECT COUNT(*) FROM scrobbles WHERE user_id = ?', ['user-1']);
        $this->assertSame(1, (int) $count);
    }

    /**
     * @param list<LastFmImportTrack> $tracks
     */
    private function makeService(array $tracks): LastFmRematchService
    {
        $repo = $this->createMock(LastFmImportTrackRepository::class);
        $repo->method('streamUnmatched')->willReturnCallback(
            function (?int $runId = null, int $limit = 0) use ($tracks): \Generator {
                foreach ($tracks as $t) {
                    if ($t->getStatus() !== LastFmImportTrack::STATUS_UNMATCHED) {
                        continue;
                    }
                    yield $t;
                }
            }
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('flush');
        $em->method('persist');

        $matcher = new ScrobbleMatcher(
            new NavidromeRepository($this->dbPath, 'admin'),
        );

        return new LastFmRematchService(
            $repo,
            $matcher,
            new NavidromeRepository($this->dbPath, 'admin'),
            $em,
        );
    }

    private function makeRun(): RunHistory
    {
        return new RunHistory(RunHistory::TYPE_LASTFM_IMPORT, 'me', 'Test import');
    }

    private function makeUnmatchedTrack(RunHistory $run, string $artist, string $title, \DateTimeImmutable $playedAt): LastFmImportTrack
    {
        return new LastFmImportTrack(
            runHistory: $run,
            artist: $artist,
            title: $title,
            album: null,
            mbid: null,
            playedAt: $playedAt,
            status: LastFmImportTrack::STATUS_UNMATCHED,
        );
    }
}
