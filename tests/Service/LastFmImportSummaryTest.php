<?php

namespace App\Tests\Service;

use App\Entity\RunHistory;
use App\Service\LastFmImportSummary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LastFmImportSummaryTest extends TestCase
{
    public function testReturnsNullForNonLastFmImportRun(): void
    {
        $entry = new RunHistory(RunHistory::TYPE_PLAYLIST, 'pl-1', 'Top last 30 days');
        $entry->setMetrics(['fetched' => 100, 'inserted' => 50]);

        $this->assertNull(LastFmImportSummary::fromRun($entry));
    }

    public function testComputesAllBucketsAndMatchedDerivation(): void
    {
        $entry = $this->makeImportRun([
            'fetched' => 1000,
            'inserted' => 700,
            'duplicates' => 100,
            'unmatched' => 150,
            'skipped' => 50,
        ]);

        $s = LastFmImportSummary::fromRun($entry);

        $this->assertNotNull($s);
        $this->assertSame(1000, $s['fetched']);
        $this->assertSame(700, $s['inserted']['n']);
        $this->assertSame(70.0, $s['inserted']['pct']);
        $this->assertSame(100, $s['duplicates']['n']);
        $this->assertSame(10.0, $s['duplicates']['pct']);
        $this->assertSame(150, $s['unmatched']['n']);
        $this->assertSame(15.0, $s['unmatched']['pct']);
        $this->assertSame(50, $s['skipped']['n']);
        $this->assertSame(5.0, $s['skipped']['pct']);
        $this->assertSame(800, $s['matched']['n']);
        $this->assertSame(80.0, $s['matched']['pct']);
    }

    public function testFetchedZeroDoesNotDivideByZero(): void
    {
        $entry = $this->makeImportRun([
            'fetched' => 0,
            'inserted' => 0,
            'duplicates' => 0,
            'unmatched' => 0,
            'skipped' => 0,
        ]);

        $s = LastFmImportSummary::fromRun($entry);

        $this->assertNotNull($s);
        $this->assertSame(0, $s['fetched']);
        foreach (['matched', 'inserted', 'duplicates', 'unmatched', 'skipped'] as $k) {
            $this->assertSame(0.0, $s[$k]['pct'], "$k pct should be 0 when fetched=0");
        }
    }

    public function testNullMetricsTreatedAsZeros(): void
    {
        $entry = new RunHistory(RunHistory::TYPE_LASTFM_IMPORT, 'lf-1', 'Import 2026-05-01');
        // metrics not set -> null

        $s = LastFmImportSummary::fromRun($entry);

        $this->assertNotNull($s);
        $this->assertSame(0, $s['fetched']);
        $this->assertSame(0, $s['inserted']['n']);
    }

    public function testMissingKeysDefaultToZero(): void
    {
        $entry = $this->makeImportRun([
            'fetched' => 10,
            'inserted' => 5,
            // duplicates / unmatched / skipped intentionally absent
        ]);

        $s = LastFmImportSummary::fromRun($entry);

        $this->assertNotNull($s);
        $this->assertSame(0, $s['duplicates']['n']);
        $this->assertSame(0, $s['unmatched']['n']);
        $this->assertSame(0, $s['skipped']['n']);
        $this->assertSame(5, $s['matched']['n']);
    }

    public function testNonNumericMetricsCoercedToZero(): void
    {
        $entry = $this->makeImportRun([
            'fetched' => 'invalid',
            'inserted' => null,
            'duplicates' => [1, 2],
            'unmatched' => 5,
        ]);

        $s = LastFmImportSummary::fromRun($entry);

        $this->assertNotNull($s);
        $this->assertSame(0, $s['fetched']);
        $this->assertSame(0, $s['inserted']['n']);
        $this->assertSame(0, $s['duplicates']['n']);
        $this->assertSame(5, $s['unmatched']['n']);
    }

    /**
     * @param array{fetched:int, inserted:int, expectedPct:float} $case
     */
    #[DataProvider('roundingCases')]
    public function testPercentageRoundingToOneDecimal(array $case): void
    {
        $entry = $this->makeImportRun([
            'fetched' => $case['fetched'],
            'inserted' => $case['inserted'],
        ]);

        $s = LastFmImportSummary::fromRun($entry);

        $this->assertNotNull($s);
        $this->assertSame($case['expectedPct'], $s['inserted']['pct']);
    }

    /**
     * @return iterable<string, array{0: array{fetched:int, inserted:int, expectedPct:float}}>
     */
    public static function roundingCases(): iterable
    {
        yield 'one third' => [['fetched' => 3, 'inserted' => 1, 'expectedPct' => 33.3]];
        yield 'two thirds' => [['fetched' => 3, 'inserted' => 2, 'expectedPct' => 66.7]];
        yield 'one out of seven' => [['fetched' => 7, 'inserted' => 1, 'expectedPct' => 14.3]];
        yield 'half' => [['fetched' => 2, 'inserted' => 1, 'expectedPct' => 50.0]];
        yield 'all' => [['fetched' => 100, 'inserted' => 100, 'expectedPct' => 100.0]];
    }

    /**
     * @param array<string, mixed> $metrics
     */
    private function makeImportRun(array $metrics): RunHistory
    {
        $entry = new RunHistory(RunHistory::TYPE_LASTFM_IMPORT, 'lf-1', 'Import 2026-05-01');
        $entry->setMetrics($metrics);
        return $entry;
    }
}
