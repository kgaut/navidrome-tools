<?php

namespace App\Tests\Service;

use App\MusicBrainz\MusicBrainzArtistCandidate;
use App\MusicBrainz\MusicBrainzClient;
use App\MusicBrainz\MusicBrainzException;
use App\Navidrome\NavidromeRepository;
use App\Repository\LastFmArtistAliasRepository;
use App\Repository\LastFmMatchCacheRepository;
use App\Repository\ScrobbleSyncRepository;
use App\Service\MusicBrainzAliasReport;
use App\Service\MusicBrainzAliasSuggester;
use App\Service\MusicBrainzAliasSuggestion;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class MusicBrainzAliasSuggesterTest extends TestCase
{
    /**
     * @param list<MusicBrainzArtistCandidate>|MusicBrainzException $mbResponse
     * @param list<array{artist: string, plays: int}>               $unmatched
     * @param list<string>                                          $libArtists
     * @param array<string, true>                                   $existingAliases
     */
    private function makeSuggester(
        array|MusicBrainzException $mbResponse,
        array $unmatched,
        array $libArtists,
        array $existingAliases = [],
    ): MusicBrainzAliasSuggester {
        $client = $this->createMock(MusicBrainzClient::class);
        $stub = $client->method('searchArtist');
        $mbResponse instanceof MusicBrainzException
            ? $stub->willThrowException($mbResponse)
            : $stub->willReturn($mbResponse);

        $navidrome = $this->createMock(NavidromeRepository::class);
        $normalized = [];
        foreach ($libArtists as $a) {
            $normalized[NavidromeRepository::normalize($a)] = true;
        }
        $navidrome->method('getKnownArtistsNormalized')->willReturn($normalized);
        $navidrome->method('getKnownArtistOriginalNames')->willReturn($libArtists);

        $syncRepo = $this->createMock(ScrobbleSyncRepository::class);
        $syncRepo->method('unmatchedArtistsWithPlays')->willReturn($unmatched);

        $aliasRepo = $this->createMock(LastFmArtistAliasRepository::class);
        $aliasRepo->method('existingSourceNorms')->willReturn($existingAliases);

        $cacheRepo = $this->createMock(LastFmMatchCacheRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);

        return new MusicBrainzAliasSuggester(
            $client,
            $navidrome,
            $syncRepo,
            $aliasRepo,
            $cacheRepo,
            $em,
        );
    }

    public function testUniqueMatchOnAliasCreatesArtistAlias(): void
    {
        $suggester = $this->makeSuggester(
            mbResponse: [
                new MusicBrainzArtistCandidate(
                    'mbid-beatles',
                    'The Beatles',
                    100,
                    ['Beatles, The'],
                ),
            ],
            unmatched: [['artist' => 'Beatles, The', 'plays' => 42]],
            libArtists: ['The Beatles'],
        );

        $report = $suggester->suggest('navidrome', dryRun: false, limit: 0);

        $this->assertSame(1, $report->aliasesCreated);
        $this->assertSame(42, $report->playsCovered);
        $this->assertSame(0, $report->ambiguous);
        $this->assertCount(1, $report->samples);
        $s = $report->samples[0];
        $this->assertSame(MusicBrainzAliasSuggestion::KIND_UNIQUE, $s->kind);
        $this->assertSame(['The Beatles'], $s->targetCandidates);
    }

    public function testDryRunDoesNotPersist(): void
    {
        $client = $this->createMock(MusicBrainzClient::class);
        $client->method('searchArtist')->willReturn([
            new MusicBrainzArtistCandidate('mbid', 'The Beatles', 100, ['Beatles, The']),
        ]);

        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->method('getKnownArtistsNormalized')->willReturn([
            NavidromeRepository::normalize('The Beatles') => true,
        ]);
        $navidrome->method('getKnownArtistOriginalNames')->willReturn(['The Beatles']);

        $syncRepo = $this->createMock(ScrobbleSyncRepository::class);
        $syncRepo->method('unmatchedArtistsWithPlays')->willReturn([
            ['artist' => 'Beatles, The', 'plays' => 42],
        ]);

        $aliasRepo = $this->createMock(LastFmArtistAliasRepository::class);
        $aliasRepo->method('existingSourceNorms')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        $cache = $this->createMock(LastFmMatchCacheRepository::class);
        $cache->expects($this->never())->method('purgeByArtist');

        $suggester = new MusicBrainzAliasSuggester(
            $client,
            $navidrome,
            $syncRepo,
            $aliasRepo,
            $cache,
            $em,
        );
        $report = $suggester->suggest('navidrome', dryRun: true, limit: 0);

        $this->assertSame(1, $report->aliasesCreated);
        $this->assertTrue($report->dryRun);
    }

    public function testAmbiguousWithoutConfirmIsSkipped(): void
    {
        $suggester = $this->makeSuggester(
            mbResponse: [
                new MusicBrainzArtistCandidate('mbid-a', 'Artist Alpha', 95, ['Sigur Ros']),
                new MusicBrainzArtistCandidate('mbid-b', 'Artist Bravo', 90, ['Sigur Rós']),
            ],
            unmatched: [['artist' => 'Sigur Ros', 'plays' => 10]],
            libArtists: ['Artist Alpha', 'Artist Bravo'],
        );

        $report = $suggester->suggest('navidrome', dryRun: false, limit: 0);

        $this->assertSame(0, $report->aliasesCreated);
        $this->assertSame(1, $report->ambiguous);
        $this->assertSame(MusicBrainzAliasSuggestion::KIND_AMBIGUOUS, $report->samples[0]->kind);
        $this->assertCount(2, $report->samples[0]->targetCandidates);
    }

    public function testInteractiveConfirmAppliesPickedTarget(): void
    {
        $suggester = $this->makeSuggester(
            mbResponse: [
                new MusicBrainzArtistCandidate('mbid-a', 'Artist Alpha', 95, []),
                new MusicBrainzArtistCandidate('mbid-b', 'Artist Bravo', 90, []),
            ],
            unmatched: [['artist' => 'Ambiguous Source', 'plays' => 5]],
            libArtists: ['Artist Alpha', 'Artist Bravo'],
        );

        $report = $suggester->suggest(
            'navidrome',
            dryRun: true,
            limit: 0,
            confirm: fn (MusicBrainzAliasSuggestion $s): string => 'Artist Bravo',
        );

        $this->assertSame(1, $report->aliasesCreated);
        $this->assertSame(0, $report->ambiguous);
    }

    public function testNoLibraryMatchCountedAsNoMatch(): void
    {
        $suggester = $this->makeSuggester(
            mbResponse: [
                new MusicBrainzArtistCandidate('mbid', 'Some Other Spelling', 92, []),
            ],
            unmatched: [['artist' => 'Unknown', 'plays' => 3]],
            libArtists: ['Totally Different Artist'],
        );

        $report = $suggester->suggest('navidrome', dryRun: false, limit: 0);

        $this->assertSame(0, $report->aliasesCreated);
        $this->assertSame(1, $report->noMatch);
        $this->assertSame(MusicBrainzAliasSuggestion::KIND_NO_MATCH, $report->samples[0]->kind);
    }

    public function testLowScoreCandidatesAreIgnored(): void
    {
        $suggester = $this->makeSuggester(
            mbResponse: [
                new MusicBrainzArtistCandidate('mbid', 'The Beatles', 50, ['Beatles, The']),
            ],
            unmatched: [['artist' => 'Beatles, The', 'plays' => 1]],
            libArtists: ['The Beatles'],
        );

        $report = $suggester->suggest('navidrome', dryRun: false, limit: 0);

        $this->assertSame(0, $report->aliasesCreated);
        $this->assertSame(1, $report->noMatch);
    }

    public function testSourceAlreadyAliasedIsSkipped(): void
    {
        $suggester = $this->makeSuggester(
            mbResponse: [
                new MusicBrainzArtistCandidate('mbid', 'The Beatles', 100, ['Beatles, The']),
            ],
            unmatched: [['artist' => 'Beatles, The', 'plays' => 99]],
            libArtists: ['The Beatles'],
            existingAliases: [NavidromeRepository::normalize('Beatles, The') => true],
        );

        $report = $suggester->suggest('navidrome', dryRun: false, limit: 0);

        $this->assertSame(0, $report->artistsQueried);
        $this->assertSame(1, $report->skippedAlreadyAliased);
    }

    public function testSourceAlreadyInLibraryIsSkipped(): void
    {
        $suggester = $this->makeSuggester(
            mbResponse: [],
            unmatched: [['artist' => 'The Beatles', 'plays' => 5]],
            libArtists: ['The Beatles'],
        );

        $report = $suggester->suggest('navidrome', dryRun: false, limit: 0);

        $this->assertSame(0, $report->artistsQueried);
        $this->assertSame(1, $report->skippedAlreadyOwned);
    }

    public function testMusicBrainzErrorIsCountedAndDoesNotAbort(): void
    {
        $suggester = $this->makeSuggester(
            mbResponse: new MusicBrainzException('boom'),
            unmatched: [['artist' => 'Whoever', 'plays' => 1]],
            libArtists: ['Whoever Else'],
        );

        $report = $suggester->suggest('navidrome', dryRun: false, limit: 0);

        $this->assertSame(1, $report->mbErrors);
        $this->assertSame(0, $report->aliasesCreated);
    }

    public function testLimitCapsNumberOfQueries(): void
    {
        $suggester = $this->makeSuggester(
            mbResponse: [],
            unmatched: [
                ['artist' => 'A', 'plays' => 5],
                ['artist' => 'B', 'plays' => 4],
                ['artist' => 'C', 'plays' => 3],
            ],
            libArtists: ['Z'],
        );

        $report = $suggester->suggest('navidrome', dryRun: false, limit: 2);

        $this->assertSame(2, $report->artistsQueried);
    }

    public function testBeforeQueryCallbackInvokedPerArtist(): void
    {
        $suggester = $this->makeSuggester(
            mbResponse: [],
            unmatched: [
                ['artist' => 'A', 'plays' => 1],
                ['artist' => 'B', 'plays' => 1],
            ],
            libArtists: ['Z'],
        );

        $seen = [];
        $suggester->suggest(
            'navidrome',
            dryRun: false,
            limit: 0,
            beforeQuery: function (string $a) use (&$seen): void {
                $seen[] = $a;
            },
        );

        $this->assertSame(['A', 'B'], $seen);
    }
}
