<?php

namespace App\Tests\Generator;

use App\Generator\AnniversaryGenerator;
use App\Navidrome\NavidromeRepository;
use App\Tests\Navidrome\NavidromeFixtureFactory;
use PHPUnit\Framework\TestCase;

class AnniversaryGeneratorTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/navidrome-anniv-' . uniqid() . '.db';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testParseOffsetsCleansAndDedupes(): void
    {
        $this->assertSame([1, 2, 5, 10], AnniversaryGenerator::parseOffsets('1, 2, 5, 10'));
        $this->assertSame([1, 5], AnniversaryGenerator::parseOffsets('5,1,5,abc,-3,0'));
        $this->assertSame([], AnniversaryGenerator::parseOffsets(''));
        $this->assertSame([1, 2], AnniversaryGenerator::parseOffsets([1, '2', 'x']));
    }

    public function testGenerateAggregatesPlaysAcrossOffsets(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath, withScrobbles: true);

        $today = new \DateTimeImmutable('today');
        $oneYearAgo = $today->modify('-1 year');
        $twoYearsAgo = $today->modify('-2 years');

        NavidromeFixtureFactory::insertTrack($conn, 'mf-A', 'Both years', 'Artist');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-B', 'Year 1 only', 'Artist');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-C', 'Year 2 only', 'Artist');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-D', 'Out of windows', 'Artist');

        // mf-A: played 1 year ago AND 2 years ago → should rank first
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-A', $oneYearAgo->format('Y-m-d 12:00:00'));
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-A', $twoYearsAgo->format('Y-m-d 12:00:00'));
        // mf-B: played 1 year ago only
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-B', $oneYearAgo->format('Y-m-d 13:00:00'));
        // mf-C: played 2 years ago only
        NavidromeFixtureFactory::insertScrobble($conn, 'user-1', 'mf-C', $twoYearsAgo->format('Y-m-d 13:00:00'));
        // mf-D: played 6 months ago — outside any window
        NavidromeFixtureFactory::insertScrobble(
            $conn,
            'user-1',
            'mf-D',
            $today->modify('-6 months')->format('Y-m-d 12:00:00'),
        );

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $gen = new AnniversaryGenerator($repo);

        $result = $gen->generate(['years_offsets' => '1,2', 'window_days' => 1], 10);

        $this->assertSame(['mf-A', 'mf-B', 'mf-C'], $result, 'mf-A first (2 plays), then mf-B & mf-C; mf-D excluded');
    }

    public function testGetActiveWindowReturnsBoundingBoxOfAllOffsets(): void
    {
        $repo = new NavidromeRepository(sys_get_temp_dir() . '/never-opened.db', 'admin');
        $gen = new AnniversaryGenerator($repo);

        $window = $gen->getActiveWindow(['years_offsets' => '1,5,10', 'window_days' => 3]);

        $this->assertNotNull($window);
        $today = new \DateTimeImmutable('today');
        $expectedFrom = $today->modify('-10 years')->modify('-3 days');
        $expectedTo = $today->modify('-1 year')->modify('+4 days');
        $this->assertSame($expectedFrom->format('Y-m-d'), $window['from']->format('Y-m-d'));
        $this->assertSame($expectedTo->format('Y-m-d'), $window['to']->format('Y-m-d'));
    }
}
