<?php

namespace App\Tests\LastFm;

use App\Entity\LastFmMatchCacheEntry;
use App\LastFm\LastFmScrobble;
use App\LastFm\MatchResult;
use App\LastFm\ScrobbleMatcher;
use App\Navidrome\NavidromeRepository;
use App\Repository\LastFmMatchCacheRepository;
use App\Tests\Navidrome\NavidromeFixtureFactory;
use PHPUnit\Framework\TestCase;

/**
 * Issue #284: the (artist, title) match cache must not override the album.
 * IAM « Petit frère » exists on the album and on « … - Instrumentales »;
 * the couple was cached on the instrumental row.
 */
class ScrobbleMatcherAlbumTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/nd-album-' . uniqid() . '.db';
        $conn = NavidromeFixtureFactory::createDatabase($this->path, withScrobbles: false);
        NavidromeFixtureFactory::insertTrack($conn, 'rylW', 'Petit Frère', 'IAM', album: 'L\'École Du Micro D\'Argent');
        NavidromeFixtureFactory::insertTrack($conn, '9XMM', 'Petit frère', 'IAM', album: 'L\'écorle Du Micro D\'argent - Instrumentales');
        NavidromeFixtureFactory::insertTrack($conn, 'mbid-row', 'Petit frère', 'IAM', album: 'Anthologie', mbzRecordingId: 'rec-1');
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

    public function testAlbumWinsOverACachedCouple(): void
    {
        $cache = $this->createMock(LastFmMatchCacheRepository::class);
        $cache->method('findByCouple')->willReturn(
            new LastFmMatchCacheEntry('IAM', 'Petit Frere', '9XMM', LastFmMatchCacheEntry::STRATEGY_COUPLE),
        );

        $result = $this->matcher($cache)->match($this->scrobble('L’École du micro d’argent'));

        $this->assertSame('rylW', $result->mediaFileId);
        $this->assertSame(LastFmMatchCacheEntry::STRATEGY_TRIPLET, $result->strategy);
    }

    public function testScrobbleWithoutAlbumStillUsesTheCache(): void
    {
        $cache = $this->createMock(LastFmMatchCacheRepository::class);
        $cache->method('findByCouple')->willReturn(
            new LastFmMatchCacheEntry('IAM', 'Petit Frere', '9XMM', LastFmMatchCacheEntry::STRATEGY_COUPLE),
        );

        $result = $this->matcher($cache)->match($this->scrobble(''));

        $this->assertSame('9XMM', $result->mediaFileId);
        $this->assertSame(MatchResult::CACHE_HIT_POSITIVE, $result->cacheStatus);
    }

    public function testMbidWinsBeforeTheCache(): void
    {
        $cache = $this->createMock(LastFmMatchCacheRepository::class);
        $cache->expects($this->never())->method('findByCouple');

        $result = $this->matcher($cache)->match(
            new LastFmScrobble('IAM', 'Petit frère', 'L\'École Du Micro D\'Argent', 'rec-1', new \DateTimeImmutable()),
        );

        $this->assertSame('mbid-row', $result->mediaFileId);
        $this->assertSame(LastFmMatchCacheEntry::STRATEGY_MBID, $result->strategy);
    }

    public function testCoupleWithoutCachePrefersTheNonEditionAlbum(): void
    {
        $result = (new ScrobbleMatcher(new NavidromeRepository($this->path, 'admin')))->match($this->scrobble('Unknown Compilation'));

        // Album unknown to the library: couple step, any row but the
        // instrumental edition (lowest id before #284).
        $this->assertNotSame('9XMM', $result->mediaFileId);
        $this->assertSame(LastFmMatchCacheEntry::STRATEGY_COUPLE, $result->strategy);
    }

    private function scrobble(string $album): LastFmScrobble
    {
        return new LastFmScrobble('IAM', 'Petit frère', $album, null, new \DateTimeImmutable());
    }

    private function matcher(LastFmMatchCacheRepository $cache): ScrobbleMatcher
    {
        return new ScrobbleMatcher(new NavidromeRepository($this->path, 'admin'), cacheRepository: $cache);
    }
}
