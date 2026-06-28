<?php

namespace App\Tests\Recommendation;

use App\Recommendation\ArtistSeed;
use App\Recommendation\ListenBrainzClient;
use App\Recommendation\ListenBrainzException;
use App\Recommendation\ListenBrainzRecommendationSource;
use PHPUnit\Framework\TestCase;

class ListenBrainzRecommendationSourceTest extends TestCase
{
    public function testInactiveWithoutUser(): void
    {
        $client = $this->createMock(ListenBrainzClient::class);
        $client->method('isConfigured')->willReturn(false);
        $client->expects($this->never())->method('recommendedRecordings');

        $source = new ListenBrainzRecommendationSource($client);
        $this->assertFalse($source->isConfigured());
        $this->assertSame([], $source->recommend([new ArtistSeed('X', 1.0)]));
        $this->assertSame('listenbrainz', $source->getName());
    }

    public function testAggregatesScoreByArtistMbid(): void
    {
        $client = $this->createMock(ListenBrainzClient::class);
        $client->method('isConfigured')->willReturn(true);
        $client->method('recommendedRecordings')->willReturn([
            ['recording_mbid' => 'rec-1', 'score' => 0.5],
            ['recording_mbid' => 'rec-2', 'score' => 0.2],
        ]);
        $client->method('resolveArtists')->willReturn([
            // Both recordings belong to artist A → scores accumulate.
            'rec-1' => [['mbid' => 'art-a', 'name' => 'Artist A']],
            'rec-2' => [
                ['mbid' => 'art-a', 'name' => 'Artist A'],
                ['mbid' => 'art-b', 'name' => 'Artist B'],
            ],
        ]);

        $rows = (new ListenBrainzRecommendationSource($client))->recommend([]);

        $byMbid = [];
        foreach ($rows as $r) {
            $byMbid[$r['mbid']] = $r;
        }

        // Artist A: (0.5 + 0.2) × 100 = 70; Artist B: 0.2 × 100 = 20.
        $this->assertEqualsWithDelta(70.0, $byMbid['art-a']['score'], 0.001);
        $this->assertEqualsWithDelta(20.0, $byMbid['art-b']['score'], 0.001);
        $this->assertSame('Artist A', $byMbid['art-a']['name']);
        // No library seed drove these — LB recs are globally personalized.
        $this->assertSame('', $byMbid['art-a']['seed']);
    }

    public function testApiErrorYieldsEmptyList(): void
    {
        $client = $this->createMock(ListenBrainzClient::class);
        $client->method('isConfigured')->willReturn(true);
        $client->method('recommendedRecordings')->willThrowException(new ListenBrainzException('boom'));

        $this->assertSame([], (new ListenBrainzRecommendationSource($client))->recommend([]));
    }
}
