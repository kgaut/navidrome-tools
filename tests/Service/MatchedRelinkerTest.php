<?php

namespace App\Tests\Service;

use App\Entity\Scrobble;
use App\Entity\ScrobbleSync;
use App\LastFm\ScrobbleMatcher;
use App\Navidrome\NavidromeDbBackup;
use App\Navidrome\NavidromeRepository;
use App\Repository\ScrobbleSyncRepository;
use App\Service\MatchedRelinker;
use App\Tests\Navidrome\NavidromeFixtureFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Issue #285: plays written on « … - Instrumentales » before #284 are moved
 * to the album track the current cascade picks.
 */
class MatchedRelinkerTest extends TestCase
{
    private string $path;
    /** @var array<int, ScrobbleSync> */
    private array $syncs = [];

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/nd-relink-' . uniqid() . '.db';
        $conn = NavidromeFixtureFactory::createDatabase($this->path, withScrobbles: true);
        NavidromeFixtureFactory::insertTrack($conn, 'album', 'Petit Frère', 'IAM', album: 'L\'École Du Micro D\'Argent');
        NavidromeFixtureFactory::insertTrack($conn, '9instru', 'Petit frère', 'IAM', album: 'L\'École - Instrumentales');
        $conn->close();
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path . '-wal', $this->path . '-shm'] as $f) {
            if (file_exists($f)) {
                unlink($f);
            }
        }
    }

    public function testPlanMovesOnlyRowsTheCascadeNowSendsElsewhere(): void
    {
        $this->sync(1, '2024-01-01 10:00:00', '9instru', 'L\'École Du Micro D\'Argent');
        $this->sync(2, '2024-01-02 10:00:00', 'album', 'L\'École Du Micro D\'Argent');

        $plan = $this->relinker()->plan('IAM', null, null, null, 100);

        $this->assertSame(2, $plan['considered']);
        $this->assertCount(1, $plan['moves']);
        $this->assertSame(['sync_id' => 1, 'from' => '9instru', 'to' => 'album'], array_intersect_key($plan['moves'][0], ['sync_id' => 1, 'from' => 1, 'to' => 1]));
        $this->assertSame('L\'École - Instrumentales', $plan['groups'][0]['from_album']);
        $this->assertSame('L\'École Du Micro D\'Argent', $plan['groups'][0]['to_album']);
    }

    public function testFromAlbumFiltersOnTheCurrentTarget(): void
    {
        $this->sync(1, '2024-01-01 10:00:00', '9instru', 'L\'École Du Micro D\'Argent');

        $this->assertSame(0, $this->relinker()->plan('IAM', null, null, 'Bonus', 100)['considered']);
        $this->assertSame(1, $this->relinker()->plan('IAM', null, null, 'instrumentales', 100)['considered']);
    }

    public function testApplyMovesTheRowAndRecountsBothTracks(): void
    {
        $nd = $this->nd();
        $this->insertPlay($nd, '9instru', '2024-01-01 10:00:00');
        $this->insertPlay($nd, '9instru', '2023-05-05 10:00:00'); // a native play that must stay
        $this->sync(1, '2024-01-01 10:00:00', '9instru', 'L\'École Du Micro D\'Argent');

        $relinker = $this->relinker();
        $out = $relinker->apply($relinker->plan('IAM', null, null, null, 100)['moves']);

        $this->assertSame(['moved' => 1, 'duplicates' => 0, 'missing_old' => 0, 'backup' => null], $out);
        $nd = $this->nd();
        $this->assertSame(1, (int) $nd->fetchOne("SELECT COUNT(*) FROM scrobbles WHERE media_file_id = '9instru'"));
        $this->assertSame(1, (int) $nd->fetchOne("SELECT COUNT(*) FROM scrobbles WHERE media_file_id = 'album'"));
        $this->assertSame(1, (int) $nd->fetchOne("SELECT play_count FROM annotation WHERE item_id = '9instru'"));
        $this->assertSame(1, (int) $nd->fetchOne("SELECT play_count FROM annotation WHERE item_id = 'album'"));
        $this->assertSame('album', $this->syncs[1]->getTargetId());
        $this->assertSame(ScrobbleSync::STATUS_MATCHED, $this->syncs[1]->getStatus());
        $nd->close();

        // Idempotent: nothing left to move.
        $this->assertSame([], $this->relinker()->plan('IAM', null, null, null, 100)['moves']);
    }

    public function testApplyMarksDuplicateWhenTheNewTrackAlreadyHasThePlay(): void
    {
        $nd = $this->nd();
        $this->insertPlay($nd, '9instru', '2024-01-01 10:00:00');
        $this->insertPlay($nd, 'album', '2024-01-01 10:00:30');
        $this->sync(1, '2024-01-01 10:00:00', '9instru', 'L\'École Du Micro D\'Argent');

        $relinker = $this->relinker();
        $out = $relinker->apply($relinker->plan('IAM', null, null, null, 100)['moves']);

        $this->assertSame(1, $out['duplicates']);
        $this->assertSame(0, $out['moved']);
        $nd = $this->nd();
        $this->assertSame(0, (int) $nd->fetchOne("SELECT COUNT(*) FROM scrobbles WHERE media_file_id = '9instru'"));
        $this->assertSame(1, (int) $nd->fetchOne("SELECT COUNT(*) FROM scrobbles WHERE media_file_id = 'album'"));
        $this->assertSame(ScrobbleSync::STATUS_DUPLICATE, $this->syncs[1]->getStatus());
        $nd->close();
    }

    public function testApplyLeavesAPlayWhoseRowIsNotFoundUntouched(): void
    {
        // #290: inserting without removing would double the play.
        $this->sync(1, '2024-01-01 10:00:00', '9instru', 'L\'École Du Micro D\'Argent');

        $relinker = $this->relinker();
        $out = $relinker->apply($relinker->plan('IAM', null, null, null, 100)['moves']);

        $this->assertSame(1, $out['missing_old']);
        $this->assertSame(0, $out['moved']);
        $nd = $this->nd();
        $this->assertSame(0, (int) $nd->fetchOne('SELECT COUNT(*) FROM scrobbles'));
        $nd->close();
        $this->assertSame('9instru', $this->syncs[1]->getTargetId());
    }

    public function testPlanLeavesRowsWhoseRecordedTargetLeftTheLibrary(): void
    {
        // Production: track ids changed after the write; the recorded target is gone.
        $this->sync(1, '2024-01-01 10:00:00', 'gone-id', 'L\'École Du Micro D\'Argent');

        $plan = $this->relinker()->plan('IAM', null, null, null, 100);

        $this->assertSame(1, $plan['stale_target']);
        $this->assertSame([], $plan['moves']);
    }

    public function testDeleteOneScrobbleRemovesASingleIdenticalRow(): void
    {
        $nd = $this->nd();
        $this->insertPlay($nd, 'album', '2024-01-01 10:00:00');
        $this->insertPlay($nd, 'album', '2024-01-01 10:00:00');
        $nd->close();

        $repo = new NavidromeRepository($this->path, 'admin');
        $this->assertSame(1, $repo->deleteOneScrobble('user-1', 'album', new \DateTimeImmutable('2024-01-01 10:00:00', new \DateTimeZone('UTC'))));
        $this->assertSame(0, $repo->deleteOneScrobble('user-1', 'album', new \DateTimeImmutable('2024-01-01 11:00:00', new \DateTimeZone('UTC'))));
        $repo->closeWriteConnection();

        $nd = $this->nd();
        $this->assertSame(1, (int) $nd->fetchOne('SELECT COUNT(*) FROM scrobbles'));
        $nd->close();
    }

    private function sync(int $id, string $playedAt, string $target, string $album): void
    {
        $scrobble = new Scrobble('alice', 'IAM', 'Petit frère', $album, null, null, null, null, new \DateTimeImmutable($playedAt, new \DateTimeZone('UTC')));
        $sync = new ScrobbleSync($scrobble, ScrobbleSync::TARGET_NAVIDROME);
        $sync->markMatched($target, 'couple');
        (new \ReflectionProperty(ScrobbleSync::class, 'id'))->setValue($sync, $id);
        $this->syncs[$id] = $sync;
    }

    private function relinker(): MatchedRelinker
    {
        $syncRepo = $this->createMock(ScrobbleSyncRepository::class);
        $syncRepo->method('matchedForRelink')->willReturnCallback(
            fn () => array_values(array_filter($this->syncs, static fn (ScrobbleSync $s) => $s->getStatus() === ScrobbleSync::STATUS_MATCHED)),
        );
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(fn (string $class, int $id) => $this->syncs[$id] ?? null);
        $backup = $this->createMock(NavidromeDbBackup::class);
        $backup->method('backup')->willReturn(null);
        $navidrome = new NavidromeRepository($this->path, 'admin');

        return new MatchedRelinker($syncRepo, new ScrobbleMatcher($navidrome), $navidrome, $backup, $em);
    }

    private function nd(): Connection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $this->path]);
    }

    private function insertPlay(Connection $nd, string $mediaFileId, string $at): void
    {
        $nd->executeStatement(
            'INSERT INTO scrobbles (media_file_id, user_id, submission_time) VALUES (?, ?, ?)',
            [$mediaFileId, 'user-1', (new \DateTimeImmutable($at, new \DateTimeZone('UTC')))->getTimestamp()],
        );
    }
}
