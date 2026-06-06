<?php

namespace App\Tests\Repository;

use App\Repository\ScrobbleRepository;
use PHPUnit\Framework\TestCase;

/**
 * Focused on the static filter-clause builder — that's the part with real
 * decision logic (date cascade validation, status NULL handling). The
 * full COUNT / SELECT paths are exercised in the running app and would
 * require a fully wired Doctrine EntityManager fixture to test, which is
 * not the convention here (cf. ScrobbleSyncRepositoryTest pattern).
 */
class ScrobbleRepositoryFilterTest extends TestCase
{
    public function testEmptyFiltersYieldsEmptyWhere(): void
    {
        [$where, $params] = ScrobbleRepository::buildFilterClauses([]);
        $this->assertSame('', $where);
        $this->assertSame([], $params);
    }

    public function testYearAloneFiltersOnYear(): void
    {
        [$where, $params] = ScrobbleRepository::buildFilterClauses(['year' => '2024']);
        $this->assertSame("strftime('%Y', s.played_at) = ?", $where);
        $this->assertSame(['2024'], $params);
    }

    public function testYearPlusMonthFiltersOnYearMonth(): void
    {
        [$where, $params] = ScrobbleRepository::buildFilterClauses(['year' => '2024', 'month' => '06']);
        $this->assertSame("strftime('%Y-%m', s.played_at) = ?", $where);
        $this->assertSame(['2024-06'], $params);
    }

    public function testFullDateFiltersOnYearMonthDay(): void
    {
        [$where, $params] = ScrobbleRepository::buildFilterClauses([
            'year' => '2024', 'month' => '6', 'day' => '15',
        ]);
        $this->assertSame("strftime('%Y-%m-%d', s.played_at) = ?", $where);
        $this->assertSame(['2024-06-15'], $params);
    }

    public function testDayWithoutMonthAndYearIsIgnored(): void
    {
        [$where, $params] = ScrobbleRepository::buildFilterClauses(['day' => '15']);
        $this->assertSame('', $where);
        $this->assertSame([], $params);
    }

    public function testMonthWithoutYearIsIgnored(): void
    {
        [$where, $params] = ScrobbleRepository::buildFilterClauses(['month' => '06']);
        $this->assertSame('', $where);
        $this->assertSame([], $params);
    }

    public function testGarbageInValuesIsRejected(): void
    {
        [$where, $params] = ScrobbleRepository::buildFilterClauses([
            'year' => 'abcd', 'month' => '13', 'day' => '32',
        ]);
        $this->assertSame('', $where);
        $this->assertSame([], $params);
    }

    public function testArtistAndTitleFiltersUseCaseInsensitiveLike(): void
    {
        [$where, $params] = ScrobbleRepository::buildFilterClauses([
            'artist' => 'Beyoncé', 'title' => 'Halo',
        ]);
        $this->assertSame('LOWER(s.artist) LIKE LOWER(?) AND LOWER(s.title) LIKE LOWER(?)', $where);
        $this->assertSame(['%Beyoncé%', '%Halo%'], $params);
    }

    public function testStatusUnmatchedAlsoMatchesScrobblesWithoutSyncRow(): void
    {
        // Scrobbles never prepared for navidrome yet have no scrobble_sync
        // row at all → LEFT JOIN yields ss.status IS NULL. From the user's
        // perspective they're as good as unmatched, so the unmatched filter
        // catches both.
        [$where, $params] = ScrobbleRepository::buildFilterClauses(['status' => 'unmatched']);
        $this->assertSame("(ss.status = 'unmatched' OR ss.status IS NULL)", $where);
        $this->assertSame([], $params);
    }

    public function testStatusOtherValuesEmitParameterizedEquality(): void
    {
        [$where, $params] = ScrobbleRepository::buildFilterClauses(['status' => 'matched']);
        $this->assertSame('ss.status = ?', $where);
        $this->assertSame(['matched'], $params);
    }

    public function testStatusAllShortCircuits(): void
    {
        [$where, $params] = ScrobbleRepository::buildFilterClauses(['status' => 'all']);
        $this->assertSame('', $where);
        $this->assertSame([], $params);
    }

    public function testCombinedFiltersChainWithAnd(): void
    {
        [$where, $params] = ScrobbleRepository::buildFilterClauses([
            'lastfm_user' => 'alice',
            'year' => '2024', 'month' => '06',
            'artist' => 'Daft Punk',
            'status' => 'matched',
        ]);
        $this->assertSame(
            "s.lastfm_user = ? AND strftime('%Y-%m', s.played_at) = ? AND LOWER(s.artist) LIKE LOWER(?) AND ss.status = ?",
            $where,
        );
        $this->assertSame(['alice', '2024-06', '%Daft Punk%', 'matched'], $params);
    }
}
