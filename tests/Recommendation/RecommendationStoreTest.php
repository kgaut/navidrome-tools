<?php

namespace App\Tests\Recommendation;

use App\Recommendation\ArtistRecommendation;
use App\Recommendation\RecommendationResult;
use App\Recommendation\RecommendationStore;
use App\Repository\SettingRepository;
use PHPUnit\Framework\TestCase;

class RecommendationStoreTest extends TestCase
{
    /**
     * A stateful SettingRepository stand-in backed by an array, so save/load
     * and ignore round-trip realistically.
     *
     * @param array<string, string> $store
     */
    private function settings(array &$store): SettingRepository
    {
        $repo = $this->createMock(SettingRepository::class);
        $repo->method('get')->willReturnCallback(
            static function (string $key, string $default = '') use (&$store): string {
                return $store[$key] ?? $default;
            },
        );
        $repo->method('set')->willReturnCallback(
            static function (string $key, string $value) use (&$store): void {
                $store[$key] = $value;
            },
        );

        return $repo;
    }

    public function testSaveAndLoadRoundTrips(): void
    {
        $store = [];
        $settings = $this->settings($store);
        $subject = new RecommendationStore($settings);

        $result = new RecommendationResult([
            new ArtistRecommendation('Aphex Twin', 'mbid-aphex', 13.0, ['lastfm'], ['Radiohead']),
            new ArtistRecommendation('Boards of Canada', null, 5.0, ['lastfm'], ['Radiohead']),
        ]);
        $at = new \DateTimeImmutable('2026-06-28T10:00:00+00:00');

        $subject->save($result, $at);
        $loaded = $subject->load();

        $this->assertNotNull($loaded);
        $this->assertSame('2026-06-28T10:00:00+00:00', $loaded['generated_at']);
        $this->assertCount(2, $loaded['items']);
        $this->assertSame('Aphex Twin', $loaded['items'][0]->name);
        $this->assertSame('mbid-aphex', $loaded['items'][0]->mbid);
        $this->assertNull($loaded['items'][1]->mbid);
    }

    public function testLoadReturnsNullWhenEmpty(): void
    {
        $store = [];
        $this->assertNull((new RecommendationStore($this->settings($store)))->load());
    }

    public function testIgnorePersistsMbidAndNormalizedName(): void
    {
        $store = [];
        $subject = new RecommendationStore($this->settings($store));

        $subject->ignore('mbid-x', 'The Prodigy');
        $subject->ignore(null, 'Justice'); // no MBID → name only

        $mbids = $subject->ignoredMbids();
        $names = $subject->ignoredNames();

        $this->assertArrayHasKey('mbid-x', $mbids);
        $this->assertArrayHasKey(\App\Navidrome\NavidromeRepository::normalize('The Prodigy'), $names);
        $this->assertArrayHasKey(\App\Navidrome\NavidromeRepository::normalize('Justice'), $names);
        $this->assertCount(1, $mbids);
    }
}
