<?php

namespace App\Tests\Service;

use App\LastFm\LastFmRequestException;
use App\LastFm\LastFmScrobble;
use App\LastFm\MatchResult;
use App\LastFm\ScrobbleMatcher;
use App\Navidrome\NavidromeRepository;
use App\Repository\LastFmMatchCacheRepository;
use App\Repository\ScrobbleSyncRepository;
use App\Service\UnmatchedDiagnoser;
use App\Service\UnmatchedRetrier;
use PHPUnit\Framework\TestCase;

/**
 * Issue #278: bulk retry of the unmatched groups.
 */
class UnmatchedRetrierTest extends TestCase
{
    public function testRetryManyRetriesEachCoupleOnceAndSumsTheOutcome(): void
    {
        $syncRepo = $this->createMock(ScrobbleSyncRepository::class);
        $syncRepo->expects($this->once())->method('aggregateUnmatched')
            ->with('navidrome', 10, 0, 'Mobb', null, null)
            ->willReturn([
                ['artist' => 'Mobb Deep', 'title' => 'Quiet Storm', 'album' => 'Murda Muzik', 'count' => 10, 'last_played_at' => ''],
                ['artist' => 'Mobb Deep', 'title' => 'Quiet Storm', 'album' => 'Best Of', 'count' => 7, 'last_played_at' => ''],
                ['artist' => 'Mobb Deep', 'title' => 'Unknown Song', 'album' => null, 'count' => 3, 'last_played_at' => ''],
            ]);
        $syncRepo->method('resetCoupleToPending')->willReturn(17);

        $matcher = $this->createMock(ScrobbleMatcher::class);
        $matcher->expects($this->exactly(2))->method('match')->willReturnCallback(
            fn (LastFmScrobble $s) => $s->title === 'Quiet Storm'
                ? MatchResult::matched('mf-1', 'triplet')
                : MatchResult::unmatched(),
        );
        $diagnoser = $this->createMock(UnmatchedDiagnoser::class);
        $diagnoser->method('diagnose')->willReturn(['reason' => UnmatchedDiagnoser::REASON_TRACK_MISSING]);
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->method('getMediaFileMetadata')->willReturn([]);

        $out = (new UnmatchedRetrier($matcher, $syncRepo, $this->createMock(LastFmMatchCacheRepository::class), $diagnoser, $navidrome))
            ->retryMany('Mobb', null, null, 10);

        $this->assertSame(3, $out['groups']);
        $this->assertSame(2, $out['couples']);
        $this->assertSame(1, $out['matched']);
        $this->assertSame(17, $out['requeued']);
        $this->assertSame(['track_missing' => 1], $out['still_unmatched']);
        $this->assertSame('triplet', $out['matches'][0]['strategy']);
        $this->assertFalse($out['aborted']);
    }

    public function testRetryManyStopsAfterFiveConsecutiveLastFmErrors(): void
    {
        $rows = [];
        for ($i = 0; $i < 8; $i++) {
            $rows[] = ['artist' => 'A', 'title' => 'T' . $i, 'album' => null, 'count' => 1, 'last_played_at' => ''];
        }
        $syncRepo = $this->createMock(ScrobbleSyncRepository::class);
        $syncRepo->method('aggregateUnmatched')->willReturn($rows);
        $matcher = $this->createMock(ScrobbleMatcher::class);
        $matcher->expects($this->exactly(5))->method('match')->willThrowException(new LastFmRequestException('timeout'));

        $out = (new UnmatchedRetrier(
            $matcher,
            $syncRepo,
            $this->createMock(LastFmMatchCacheRepository::class),
            $this->createMock(UnmatchedDiagnoser::class),
            $this->createMock(NavidromeRepository::class),
        ))->retryMany(null, null, null, 50);

        $this->assertSame(5, $out['api_errors']);
        $this->assertTrue($out['aborted']);
        $this->assertSame(0, $out['matched']);
    }
}
