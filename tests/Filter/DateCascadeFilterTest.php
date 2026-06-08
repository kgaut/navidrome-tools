<?php

namespace App\Tests\Filter;

use App\Filter\DateCascadeFilter;
use PHPUnit\Framework\TestCase;

class DateCascadeFilterTest extends TestCase
{
    public function testAllNullWhenNothingProvided(): void
    {
        $this->assertSame(
            ['year' => null, 'month' => null, 'day' => null],
            DateCascadeFilter::parse(null, null, null),
        );
    }

    public function testYearAloneIsKept(): void
    {
        $this->assertSame(
            ['year' => 2024, 'month' => null, 'day' => null],
            DateCascadeFilter::parse('2024', null, null),
        );
    }

    public function testYearPlusMonth(): void
    {
        $this->assertSame(
            ['year' => 2024, 'month' => 6, 'day' => null],
            DateCascadeFilter::parse('2024', '06', null),
        );
    }

    public function testFullCascade(): void
    {
        $this->assertSame(
            ['year' => 2024, 'month' => 6, 'day' => 15],
            DateCascadeFilter::parse('2024', '6', '15'),
        );
    }

    public function testMonthIgnoredWithoutYear(): void
    {
        $this->assertSame(
            ['year' => null, 'month' => null, 'day' => null],
            DateCascadeFilter::parse(null, '06', null),
        );
    }

    public function testDayIgnoredWithoutMonth(): void
    {
        $this->assertSame(
            ['year' => 2024, 'month' => null, 'day' => null],
            DateCascadeFilter::parse('2024', null, '15'),
        );
    }

    public function testGarbageDateValuesRejected(): void
    {
        $this->assertSame(
            ['year' => null, 'month' => null, 'day' => null],
            DateCascadeFilter::parse('abcd', '13', '32'),
        );
    }

    public function testNonStringValuesRejected(): void
    {
        $this->assertSame(
            ['year' => null, 'month' => null, 'day' => null],
            DateCascadeFilter::parse(2024, 6, 15),
        );
    }

    public function testToSqlClauseNullWhenYearMissing(): void
    {
        $this->assertNull(DateCascadeFilter::toSqlClause(null, 6, 15, 'played_at'));
    }

    public function testToSqlClauseYearOnly(): void
    {
        $c = DateCascadeFilter::toSqlClause(2024, null, null, 'played_at');

        $this->assertSame("strftime('%Y', played_at) = :dc_y", $c['clause']);
        $this->assertSame('dc_y', $c['paramName']);
        $this->assertSame('2024', $c['paramValue']);
    }

    public function testToSqlClauseYearMonth(): void
    {
        $c = DateCascadeFilter::toSqlClause(2024, 6, null, 's.played_at');

        $this->assertSame("strftime('%Y-%m', s.played_at) = :dc_ym", $c['clause']);
        $this->assertSame('2024-06', $c['paramValue']);
    }

    public function testToSqlClauseFullDate(): void
    {
        $c = DateCascadeFilter::toSqlClause(2024, 6, 5, 'played_at');

        $this->assertSame("strftime('%Y-%m-%d', played_at) = :dc_ymd", $c['clause']);
        $this->assertSame('2024-06-05', $c['paramValue']);
    }

    public function testToSqlClauseUnixepochWrapsModifier(): void
    {
        $c = DateCascadeFilter::toSqlClause(2024, null, null, 's.submission_time', unixepoch: true);

        $this->assertSame("strftime('%Y', s.submission_time, 'unixepoch') = :dc_y", $c['clause']);
    }

    public function testToSqlClauseCustomPrefixAvoidsParamCollision(): void
    {
        // Both call sites carry surrounding `:user` / `:uid` clauses ; the
        // helper supports a custom prefix to keep param names unique.
        $c = DateCascadeFilter::toSqlClause(2024, null, null, 'played_at', paramPrefix: 'top_');

        $this->assertSame("strftime('%Y', played_at) = :top_y", $c['clause']);
        $this->assertSame('top_y', $c['paramName']);
    }
}
