<?php

namespace App\Tests\Service;

use App\Entity\ScrobbleSync;
use App\Navidrome\NavidromeRepository;
use App\Repository\LastFmArtistAliasRepository;
use App\Repository\LastFmMatchCacheRepository;
use App\Repository\ScrobbleSyncRepository;
use App\Repository\SettingRepository;
use App\Service\UnmatchedCatchUp;
use App\Tests\Navidrome\NavidromeFixtureFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Issue #278: unmatched scrobbles retried at the start of each sync — after
 * a library addition, and in rotation once their miss has expired.
 */
class UnmatchedCatchUpTest extends TestCase
{
    private string $path;
    private ScrobbleSyncRepository&MockObject $syncRepo;
    private LastFmMatchCacheRepository&MockObject $cache;
    private SettingRepository&MockObject $settings;
    /** @var array<string, string> */
    private array $stored = [];
    /** @var list<string> */
    private array $requeued = [];

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/nd-catchup-' . uniqid() . '.db';
        $conn = NavidromeFixtureFactory::createDatabase($this->path, withScrobbles: false);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-1', 'Old', 'Old Artist', createdAt: '2025-01-01 10:00:00');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-2', 'Quiet Storm', 'Mobb Deep', createdAt: '2026-11-01 10:00:00');
        $conn->close();

        $this->syncRepo = $this->createMock(ScrobbleSyncRepository::class);
        $this->syncRepo->method('unmatchedCouples')->with(ScrobbleSync::TARGET_NAVIDROME)->willReturn([
            ['artist' => 'Mobb Deep feat. Lil Kim', 'title' => 'Quiet Storm', 'mbid_albums' => null, 'plays' => 17],
            ['artist' => 'Gaël Faye', 'title' => 'Irruption', 'mbid_albums' => null, 'plays' => 12],
            ['artist' => 'Guts', 'title' => 'Take a Look Around You', 'mbid_albums' => null, 'plays' => 5],
            ['artist' => 'Nobody', 'title' => 'Nothing', 'mbid_albums' => null, 'plays' => 1],
        ]);
        $this->syncRepo->method('resetCoupleToPending')->willReturnCallback(function (string $t, string $a, string $title): int {
            $this->requeued[] = $a . ' / ' . $title;

            return 2;
        });
        $this->cache = $this->createMock(LastFmMatchCacheRepository::class);
        $this->settings = $this->createMock(SettingRepository::class);
        $this->settings->method('get')->willReturnCallback(fn (string $k, string $d = '') => $this->stored[$k] ?? $d);
        $this->settings->method('set')->willReturnCallback(function (string $k, string $v): void {
            $this->stored[$k] = $v;
        });
    }

    protected function tearDown(): void
    {
        foreach ([$this->path, $this->path . '-wal', $this->path . '-shm'] as $f) {
            if (file_exists($f)) {
                unlink($f);
            }
        }
    }

    public function testFirstRunOnlyRecordsTheWatermark(): void
    {
        $this->cache->method('freshNegativeKeys')->willReturn($this->allFresh());
        $this->cache->expects($this->never())->method('purgeByCouple');

        $out = $this->catchUp()->run();

        $this->assertSame(['library_couples' => 0, 'expired_couples' => 0, 'requeued' => 0], $out);
        $this->assertSame('2026-11-01 10:00:00', $this->watermark());
    }

    public function testLibraryAdditionRequeuesTheAddedArtistsCouplesOnly(): void
    {
        $this->stored[UnmatchedCatchUp::SETTING_WATERMARK] = '2026-06-01 00:00:00';
        $this->cache->method('freshNegativeKeys')->willReturn($this->allFresh());
        $this->cache->expects($this->once())->method('purgeByCouple')->with('Mobb Deep feat. Lil Kim', 'Quiet Storm');

        $out = $this->catchUp()->run();

        $this->assertSame(1, $out['library_couples']);
        $this->assertSame(0, $out['expired_couples']);
        $this->assertSame(2, $out['requeued']);
        $this->assertSame(['Mobb Deep feat. Lil Kim / Quiet Storm'], $this->requeued);
        $this->assertSame('2026-11-01 10:00:00', $this->watermark());
    }

    public function testArtistAliasTargetCountsAsTheAddedArtist(): void
    {
        $this->stored[UnmatchedCatchUp::SETTING_WATERMARK] = '2026-06-01 00:00:00';
        $this->cache->method('freshNegativeKeys')->willReturn($this->allFresh());
        $aliases = $this->createMock(LastFmArtistAliasRepository::class);
        $aliases->method('resolve')->willReturnCallback(fn (string $a) => $a === 'Nobody' ? 'Mobb Deep' : null);

        $out = $this->catchUp(aliases: $aliases)->run();

        $this->assertSame(2, $out['library_couples']);
        $this->assertContains('Nobody / Nothing', $this->requeued);
    }

    public function testExpiredMissesAreRetriedMostPlayedFirstWithinTheCap(): void
    {
        $this->stored[UnmatchedCatchUp::SETTING_WATERMARK] = '2026-11-01 10:00:00';
        // Only « Nobody / Nothing » has a fresh miss.
        $this->cache->method('freshNegativeKeys')->with(30)->willReturn(["nobody\0nothing" => true]);
        $this->cache->expects($this->never())->method('purgeByCouple');

        $out = $this->catchUp(perRun: 2)->run();

        $this->assertSame(0, $out['library_couples']);
        $this->assertSame(2, $out['expired_couples']);
        $this->assertSame(['Mobb Deep feat. Lil Kim / Quiet Storm', 'Gaël Faye / Irruption'], $this->requeued);
    }

    public function testExpiredRetryDisabledByZeroOrNoTtl(): void
    {
        $this->stored[UnmatchedCatchUp::SETTING_WATERMARK] = '2026-11-01 10:00:00';
        $this->cache->expects($this->never())->method('freshNegativeKeys');

        $this->assertSame(0, $this->catchUp(perRun: 0)->run()['expired_couples']);
        $this->assertSame(0, $this->catchUp(ttl: 0)->run()['expired_couples']);
        $this->assertSame([], $this->requeued);
    }

    /** Read through a call: the settings mock writes the property behind PHPStan's back. */
    private function watermark(): string
    {
        return $this->stored[UnmatchedCatchUp::SETTING_WATERMARK] ?? '';
    }

    /** @return array<string, true> */
    private function allFresh(): array
    {
        $keys = [];
        foreach ($this->syncRepo->unmatchedCouples(ScrobbleSync::TARGET_NAVIDROME) as $c) {
            $keys[NavidromeRepository::normalize($c['artist']) . "\0" . NavidromeRepository::normalize($c['title'])] = true;
        }

        return $keys;
    }

    private function catchUp(?LastFmArtistAliasRepository $aliases = null, int $ttl = 30, int $perRun = 200): UnmatchedCatchUp
    {
        return new UnmatchedCatchUp(
            new NavidromeRepository($this->path, 'admin'),
            $this->syncRepo,
            $this->cache,
            $this->settings,
            $aliases,
            $ttl,
            $perRun,
        );
    }
}
