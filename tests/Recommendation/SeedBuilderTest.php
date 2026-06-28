<?php

namespace App\Tests\Recommendation;

use App\Navidrome\NavidromeRepository;
use App\Recommendation\ArtistSeed;
use App\Recommendation\SeedBuilder;
use PHPUnit\Framework\TestCase;

class SeedBuilderTest extends TestCase
{
    public function testCombinesTopRecentAndLovedWeights(): void
    {
        $navidrome = $this->createMock(NavidromeRepository::class);

        // First arg null → all-time top; non-null → recent (current year).
        $navidrome->method('getTopArtistsWithDates')->willReturnCallback(
            static function (?int $year) {
                if ($year === null) {
                    return [
                        ['artist' => 'Radiohead', 'plays' => 100],
                        ['artist' => 'Aphex Twin', 'plays' => 40],
                    ];
                }

                return [['artist' => 'Radiohead', 'plays' => 10]]; // recent boost ×1.5
            },
        );

        $navidrome->method('iterateStarredMediaFiles')->willReturnCallback(
            static function (): \Generator {
                yield ['artist' => 'Aphex Twin'];
                yield ['artist' => 'Aphex Twin'];
                yield ['artist' => 'Boards of Canada'];
            },
        );

        $seeds = (new SeedBuilder($navidrome))->build(10);

        $byName = [];
        foreach ($seeds as $s) {
            $this->assertInstanceOf(ArtistSeed::class, $s);
            $byName[$s->name] = $s->weight;
        }

        // Radiohead: 100 (all-time) + 10×1.5 (recent) = 115.
        $this->assertEqualsWithDelta(115.0, $byName['Radiohead'], 0.001);
        // Aphex Twin: 40 (all-time) + 2 loved ×10 = 60.
        $this->assertEqualsWithDelta(60.0, $byName['Aphex Twin'], 0.001);
        // Boards of Canada: 1 loved ×10 = 10.
        $this->assertEqualsWithDelta(10.0, $byName['Boards of Canada'], 0.001);

        // Ranked by weight, strongest first.
        $this->assertSame('Radiohead', $seeds[0]->name);
        $this->assertSame('Aphex Twin', $seeds[1]->name);
    }

    public function testDedupesByNormalizedNameAndRespectsLimit(): void
    {
        $navidrome = $this->createMock(NavidromeRepository::class);
        $navidrome->method('getTopArtistsWithDates')->willReturnCallback(
            static function (?int $year) {
                if ($year === null) {
                    return [
                        ['artist' => 'The Beatles', 'plays' => 50],
                        ['artist' => 'the beatles', 'plays' => 30], // same normalized
                        ['artist' => 'Pink Floyd', 'plays' => 20],
                    ];
                }

                return [];
            },
        );
        $navidrome->method('iterateStarredMediaFiles')->willReturnCallback(
            static fn (): \Generator => yield from [],
        );

        $seeds = (new SeedBuilder($navidrome))->build(1);

        // Limit honoured.
        $this->assertCount(1, $seeds);
        // The two Beatles spellings merged to 80 → top seed.
        $this->assertSame('The Beatles', $seeds[0]->name);
        $this->assertEqualsWithDelta(80.0, $seeds[0]->weight, 0.001);
    }
}
