<?php

namespace App\Tests\Generator;

use App\Generator\SongsYouUsedToLoveGenerator;
use App\Navidrome\NavidromeRepository;
use App\Tests\Navidrome\NavidromeFixtureFactory;
use PHPUnit\Framework\TestCase;

class SongsYouUsedToLoveGeneratorTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/navidrome-loved-' . uniqid() . '.db';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->dbPath)) {
            unlink($this->dbPath);
        }
    }

    public function testGenerateReturnsLovedAndForgottenTracks(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-loved-old', 'Old Favorite', 'A');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-loved-recent', 'Still Playing', 'B');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-rare-old', 'Niche', 'C');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-loved-older', 'Forgotten Hit', 'D');

        // mf-loved-old: 10 plays, last play 1 year ago → INCLUDED
        NavidromeFixtureFactory::insertAnnotation($conn, 'user-1', 'mf-loved-old', 10, date('Y-m-d H:i:s', strtotime('-1 year')));
        // mf-loved-recent: 8 plays, last play 1 month ago → EXCLUDED (still recent)
        NavidromeFixtureFactory::insertAnnotation($conn, 'user-1', 'mf-loved-recent', 8, date('Y-m-d H:i:s', strtotime('-1 month')));
        // mf-rare-old: 2 plays, last play 1 year ago → EXCLUDED (under min_plays)
        NavidromeFixtureFactory::insertAnnotation($conn, 'user-1', 'mf-rare-old', 2, date('Y-m-d H:i:s', strtotime('-1 year')));
        // mf-loved-older: 15 plays, last play 2 years ago → INCLUDED, ranked first by play_count
        NavidromeFixtureFactory::insertAnnotation($conn, 'user-1', 'mf-loved-older', 15, date('Y-m-d H:i:s', strtotime('-2 years')));

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $generator = new SongsYouUsedToLoveGenerator($repo);

        $result = $generator->generate(['min_plays' => 5, 'months_silent' => 6], 10);

        $this->assertSame(['mf-loved-older', 'mf-loved-old'], $result, 'Sorted by play_count DESC; recent + rare excluded.');
    }

    public function testCustomThresholds(): void
    {
        $conn = NavidromeFixtureFactory::createDatabase($this->dbPath);
        NavidromeFixtureFactory::insertTrack($conn, 'mf-3', '3 plays', 'A');
        NavidromeFixtureFactory::insertTrack($conn, 'mf-7', '7 plays', 'B');

        NavidromeFixtureFactory::insertAnnotation($conn, 'user-1', 'mf-3', 3, date('Y-m-d H:i:s', strtotime('-90 days')));
        NavidromeFixtureFactory::insertAnnotation($conn, 'user-1', 'mf-7', 7, date('Y-m-d H:i:s', strtotime('-90 days')));

        $repo = new NavidromeRepository($this->dbPath, 'admin');
        $generator = new SongsYouUsedToLoveGenerator($repo);

        // min_plays=3 → both qualify
        $this->assertSame(['mf-7', 'mf-3'], $generator->generate(['min_plays' => 3, 'months_silent' => 2], 10));
        // min_plays=5 → only mf-7
        $this->assertSame(['mf-7'], $generator->generate(['min_plays' => 5, 'months_silent' => 2], 10));
        // months_silent=12 → cutoff way in the past, nothing qualifies
        $this->assertSame([], $generator->generate(['min_plays' => 1, 'months_silent' => 12], 10));
    }

    public function testParameterSchemaExposesBothKnobs(): void
    {
        $generator = new SongsYouUsedToLoveGenerator(new NavidromeRepository($this->dbPath, 'admin'));
        $schema = $generator->getParameterSchema();

        $names = array_map(static fn ($p) => $p->name, $schema);
        $this->assertSame(['min_plays', 'months_silent'], $names);
        $this->assertSame('songs-you-used-to-love', $generator->getKey());
    }
}
