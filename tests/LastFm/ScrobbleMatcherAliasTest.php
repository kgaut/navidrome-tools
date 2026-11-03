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
        $navidrome->expects($this->never())->method('findMediaFileByArtistTitle');

        $result = (new ScrobbleMatcher($navidrome, $aliases))->match(
            new LastFmScrobble('Mobb Deep', 'Taj Mahal', '', null, new \DateTimeImmutable()),
        );

        $this->assertSame(MatchResult::STATUS_MATCHED, $result->status);
        $this->assertSame('mf-1', $result->mediaFileId);
        $this->assertSame(MatchResult::STRATEGY_ALIAS, $result->strategy);
    }
}
