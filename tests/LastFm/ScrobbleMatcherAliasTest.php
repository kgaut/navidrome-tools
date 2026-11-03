<?php

namespace App\Tests\LastFm;

use App\Entity\LastFmAlias;
use App\LastFm\LastFmScrobble;
use App\LastFm\MatchResult;
use App\LastFm\ScrobbleMatcher;
use App\Navidrome\NavidromeRepository;
use App\Repository\LastFmAliasRepository;
use PHPUnit\Framework\TestCase;

/**
 * Issue #280: a match resolved by a track alias reports the « alias »
 * strategy (it used to be null, shown « stratégie ? » by the MCP).
 */
class ScrobbleMatcherAliasTest extends TestCase
{
    public function testTrackAliasMatchReportsTheAliasStrategy(): void
    {
        $aliases = $this->createMock(LastFmAliasRepository::class);
        $aliases->method('findByScrobble')->willReturn(new LastFmAlias('Mobb Deep', 'Taj Mahal', 'mf-1'));
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->method('mediaFileExists')->with('mf-1')->willReturn(true);
        $navidrome->expects($this->never())->method('findMediaFileByArtistTitle');

        $result = (new ScrobbleMatcher($navidrome, $aliases))->match(
            new LastFmScrobble('Mobb Deep', 'Taj Mahal', '', null, new \DateTimeImmutable()),
        );

        $this->assertSame(MatchResult::STATUS_MATCHED, $result->status);
        $this->assertSame('mf-1', $result->mediaFileId);
        $this->assertSame(MatchResult::STRATEGY_ALIAS, $result->strategy);
    }

    public function testAliasToATrackGoneFromTheLibraryFallsThroughToTheCascade(): void
    {
        // #291: ids re-issued by Navidrome; writing to the old id would leave a dangling play.
        $aliases = $this->createMock(LastFmAliasRepository::class);
        $aliases->method('findByScrobble')->willReturn(new LastFmAlias('Mobb Deep', 'Taj Mahal', 'gone'));
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->method('mediaFileExists')->willReturn(false);
        $navidrome->expects($this->once())->method('findMediaFileByArtistTitle')->willReturn('live-id');

        $result = (new ScrobbleMatcher($navidrome, $aliases))->match(
            new LastFmScrobble('Mobb Deep', 'Taj Mahal', '', null, new \DateTimeImmutable()),
        );

        $this->assertSame('live-id', $result->mediaFileId);
        $this->assertSame('couple', $result->strategy);
    }
}
