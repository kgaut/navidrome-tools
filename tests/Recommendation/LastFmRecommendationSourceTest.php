<?php

namespace App\Tests\Recommendation;

use App\LastFm\LastFmApiException;
use App\LastFm\LastFmClient;
use App\Recommendation\ArtistSeed;
use App\Recommendation\LastFmRecommendationSource;
use PHPUnit\Framework\TestCase;

class LastFmRecommendationSourceTest extends TestCase
{
    public function testUnconfiguredWithoutApiKey(): void
    {
        $source = new LastFmRecommendationSource($this->createMock(LastFmClient::class), '');
        $this->assertFalse($source->isConfigured());
        $this->assertSame([], $source->recommend([new ArtistSeed('X', 1.0)]));
        $this->assertSame('lastfm', $source->getName());
    }

    public function testAccumulatesScoreByMatchTimesSeedWeight(): void
    {
        $client = $this->createMock(LastFmClient::class);
        $client->method('artistGetSimilar')->willReturnCallback(
            static function (string $apiKey, string $artist): array {
                if ($artist === 'Radiohead') {
                    return [
                        ['name' => 'Thom Yorke', 'mbid' => 'mbid-thom', 'match' => 1.0, 'url' => ''],
                        ['name' => 'Portishead', 'mbid' => null, 'match' => 0.5, 'url' => ''],
                    ];
                }

                // Aphex Twin also surfaces Portishead.
                return [
                    ['name' => 'Portishead', 'mbid' => 'mbid-porti', 'match' => 0.4, 'url' => ''],
                ];
            },
        );

        $source = new LastFmRecommendationSource($client, 'key');
        $rows = $source->recommend([
            new ArtistSeed('Radiohead', 10.0),
            new ArtistSeed('Aphex Twin', 5.0),
        ]);

        $byName = [];
        foreach ($rows as $r) {
            $byName[$r['name']] = $r;
        }

        // Thom Yorke: 1.0 × 10 = 10.
        $this->assertEqualsWithDelta(10.0, $byName['Thom Yorke']['score'], 0.001);
        // Portishead: 0.5×10 + 0.4×5 = 7.0, MBID backfilled from the 2nd hit.
        $this->assertEqualsWithDelta(7.0, $byName['Portishead']['score'], 0.001);
        $this->assertSame('mbid-porti', $byName['Portishead']['mbid']);
        // Strongest seed wins attribution (Radiohead weight 10 > Aphex 5).
        $this->assertSame('Radiohead', $byName['Portishead']['seed']);
    }

    public function testSwallowsPerSeedApiErrors(): void
    {
        $client = $this->createMock(LastFmClient::class);
        $client->method('artistGetSimilar')->willReturnCallback(
            static function (string $apiKey, string $artist): array {
                if ($artist === 'Bad Seed') {
                    throw new LastFmApiException(6, 'unknown artist');
                }

                return [['name' => 'Good Match', 'mbid' => null, 'match' => 1.0, 'url' => '']];
            },
        );

        $source = new LastFmRecommendationSource($client, 'key');
        $rows = $source->recommend([
            new ArtistSeed('Bad Seed', 1.0),
            new ArtistSeed('Good Seed', 1.0),
        ]);

        $this->assertCount(1, $rows);
        $this->assertSame('Good Match', $rows[0]['name']);
    }
}
