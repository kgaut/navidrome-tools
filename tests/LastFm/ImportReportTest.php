<?php

namespace App\Tests\LastFm;

use App\LastFm\ImportReport;
use App\LastFm\LastFmScrobble;
use PHPUnit\Framework\TestCase;

class ImportReportTest extends TestCase
{
    public function testUnmatchedAggregatesByArtistTitleCaseInsensitive(): void
    {
        $report = new ImportReport();
        $report->recordUnmatched($this->scrobble('Daft Punk', 'One More Time'));
        $report->recordUnmatched($this->scrobble('daft punk', '  one more time '));
        $report->recordUnmatched($this->scrobble('Daft Punk', 'One More Time'));
        $report->recordUnmatched($this->scrobble('Aphex Twin', 'Xtal'));

        $this->assertSame(4, $report->unmatched);
        $this->assertSame(2, $report->distinctUnmatched());

        $ranking = $report->unmatchedRanking();
        $this->assertSame('Daft Punk', $ranking[0]['artist']);
        $this->assertSame(3, $ranking[0]['count']);
        $this->assertSame('Aphex Twin', $ranking[1]['artist']);
        $this->assertSame(1, $ranking[1]['count']);
    }

    public function testRankingHonoursLimit(): void
    {
        $report = new ImportReport();
        for ($i = 0; $i < 10; $i++) {
            $report->recordUnmatched($this->scrobble("A$i", "T$i"));
            // Make A0 the most-played to anchor the order.
            if ($i === 0) {
                for ($j = 0; $j < 5; $j++) {
                    $report->recordUnmatched($this->scrobble('A0', 'T0'));
                }
            }
        }

        $top3 = $report->unmatchedRanking(3);
        $this->assertCount(3, $top3);
        $this->assertSame('A0', $top3[0]['artist']);
        $this->assertSame(6, $top3[0]['count']);
    }

    private function scrobble(string $artist, string $title): LastFmScrobble
    {
        return new LastFmScrobble(
            artist: $artist,
            title: $title,
            album: '',
            mbid: null,
            playedAt: new \DateTimeImmutable(),
        );
    }
}
