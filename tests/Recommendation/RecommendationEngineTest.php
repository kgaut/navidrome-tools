<?php

namespace App\Tests\Recommendation;

use App\Lidarr\LidarrClient;
use App\MusicBrainz\MusicBrainzArtistCandidate;
use App\MusicBrainz\MusicBrainzClient;
use App\Navidrome\NavidromeRepository;
use App\Recommendation\ArtistSeed;
use App\Recommendation\RecommendationEngine;
use App\Recommendation\RecommendationSourceInterface;
use App\Recommendation\RecommendationStore;
use PHPUnit\Framework\TestCase;

class RecommendationEngineTest extends TestCase
{
    public function testMergesRanksExcludesAndResolvesMbids(): void
    {
        $seedBuilder = $this->seedBuilderReturning([new ArtistSeed('Radiohead', 10.0)]);

        $sourceA = $this->source('lastfm', [
            ['name' => 'Aphex Twin', 'mbid' => 'mbid-aphex', 'score' => 10.0, 'seed' => 'Radiohead'],
            ['name' => 'Owned Artist', 'mbid' => null, 'score' => 8.0, 'seed' => 'Radiohead'],
            ['name' => 'In Lidarr Artist', 'mbid' => null, 'score' => 6.0, 'seed' => 'Radiohead'],
            ['name' => 'Boards of Canada', 'mbid' => null, 'score' => 5.0, 'seed' => 'Radiohead'],
        ]);
        $sourceB = $this->source('listenbrainz', [
            ['name' => 'Aphex Twin', 'mbid' => null, 'score' => 3.0, 'seed' => 'Radiohead'],
            ['name' => 'Ignored Artist', 'mbid' => null, 'score' => 20.0, 'seed' => 'Radiohead'],
        ]);

        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->method('getKnownArtistsNormalized')
            ->willReturn([NavidromeRepository::normalize('Owned Artist') => true]);

        $mb = $this->createMock(MusicBrainzClient::class);
        $mb->method('searchArtist')->willReturnCallback(
            static function (string $name): array {
                if ($name === 'Boards of Canada') {
                    return [new MusicBrainzArtistCandidate('mbid-boc', 'Boards of Canada', 90, [])];
                }
                if ($name === 'In Lidarr Artist') {
                    return [new MusicBrainzArtistCandidate('mbid-inlidarr', 'In Lidarr Artist', 95, [])];
                }

                return [];
            },
        );

        $lidarr = $this->createMock(LidarrClient::class);
        $lidarr->method('isConfigured')->willReturn(true);
        $lidarr->method('existingArtistMbids')->willReturn(['mbid-inlidarr' => true]);

        $store = $this->createMock(RecommendationStore::class);
        $store->method('ignoredNames')->willReturn([NavidromeRepository::normalize('Ignored Artist') => true]);
        $store->method('ignoredMbids')->willReturn([]);

        $engine = new RecommendationEngine(
            [$sourceA, $sourceB],
            $seedBuilder,
            $navidrome,
            $mb,
            $lidarr,
            $store,
            seedLimit: 25,
            defaultLimit: 50,
        );

        $throttleCalls = 0;
        $result = $engine->compute(null, function () use (&$throttleCalls): void {
            ++$throttleCalls;
        });

        $names = array_map(static fn ($r) => $r->name, $result->recommendations);
        // Owned / ignored / already-in-Lidarr dropped; the rest ranked by score.
        $this->assertSame(['Aphex Twin', 'Boards of Canada'], $names);

        $aphex = $result->recommendations[0];
        $this->assertEqualsWithDelta(13.0, $aphex->score, 0.001); // 10 + 3
        $this->assertSame('mbid-aphex', $aphex->mbid);
        $this->assertSame(['lastfm', 'listenbrainz'], $aphex->sources);

        $boc = $result->recommendations[1];
        $this->assertSame('mbid-boc', $boc->mbid); // resolved via MusicBrainz

        // Two MB lookups (Boards of Canada + In Lidarr Artist); throttle hit each.
        $this->assertSame(2, $result->mbidLookups);
        $this->assertSame(2, $throttleCalls);
        $this->assertSame(1, $result->seedCount);
    }

    public function testSkipsUnconfiguredSourcesAndCapsToLimit(): void
    {
        $seedBuilder = $this->seedBuilderReturning([new ArtistSeed('Seed', 1.0)]);

        $configured = $this->source('lastfm', [
            ['name' => 'Alpha', 'mbid' => 'm-a', 'score' => 9.0, 'seed' => 'Seed'],
            ['name' => 'Beta', 'mbid' => 'm-b', 'score' => 8.0, 'seed' => 'Seed'],
        ]);

        $unconfigured = $this->createMock(RecommendationSourceInterface::class);
        $unconfigured->method('isConfigured')->willReturn(false);
        $unconfigured->expects($this->never())->method('recommend');

        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->method('getKnownArtistsNormalized')->willReturn([]);

        $lidarr = $this->createMock(LidarrClient::class);
        $lidarr->method('isConfigured')->willReturn(false);

        $store = $this->createMock(RecommendationStore::class);
        $store->method('ignoredNames')->willReturn([]);
        $store->method('ignoredMbids')->willReturn([]);

        $engine = new RecommendationEngine(
            [$configured, $unconfigured],
            $seedBuilder,
            $navidrome,
            $this->createMock(MusicBrainzClient::class),
            $lidarr,
            $store,
        );

        $result = $engine->compute(1);

        $this->assertCount(1, $result->recommendations);
        $this->assertSame('Alpha', $result->recommendations[0]->name);
    }

    public function testEmptySeedsShortCircuits(): void
    {
        $seedBuilder = $this->seedBuilderReturning([]);
        $source = $this->createMock(RecommendationSourceInterface::class);
        $source->expects($this->never())->method('recommend');

        $engine = new RecommendationEngine(
            [$source],
            $seedBuilder,
            $this->createMock(NavidromeRepository::class),
            $this->createMock(MusicBrainzClient::class),
            $this->createMock(LidarrClient::class),
            $this->createMock(RecommendationStore::class),
        );

        $result = $engine->compute();
        $this->assertSame(0, $result->count());
    }

    /**
     * @param list<ArtistSeed> $seeds
     */
    private function seedBuilderReturning(array $seeds): \App\Recommendation\SeedBuilder
    {
        $builder = $this->createMock(\App\Recommendation\SeedBuilder::class);
        $builder->method('build')->willReturn($seeds);

        return $builder;
    }

    /**
     * @param list<array{name: string, mbid: ?string, score: float, seed: string}> $rows
     */
    private function source(string $name, array $rows): RecommendationSourceInterface
    {
        $source = $this->createMock(RecommendationSourceInterface::class);
        $source->method('getName')->willReturn($name);
        $source->method('isConfigured')->willReturn(true);
        $source->method('recommend')->willReturn($rows);

        return $source;
    }
}
