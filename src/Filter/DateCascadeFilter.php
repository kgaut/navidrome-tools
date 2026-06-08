<?php

namespace App\Filter;

/**
 * Validates the year / month / day cascade used by the scrobble history
 * and top-artist pages : `day` requires `month`+`year`, `month` requires
 * `year`. Garbage in is silently ignored — we'd rather show the unfiltered
 * default than 500 on a malformed query string.
 *
 * Returns the three components as `?int` so callers can build SQL clauses
 * without re-parsing.
 */
final class DateCascadeFilter
{
    /**
     * @return array{year: ?int, month: ?int, day: ?int}
     */
    public static function parse(mixed $year, mixed $month, mixed $day): array
    {
        $y = self::asYear($year);
        $m = $y !== null ? self::asMonth($month) : null;
        $d = $m !== null ? self::asDay($day) : null;

        return ['year' => $y, 'month' => $m, 'day' => $d];
    }

    private static function asYear(mixed $v): ?int
    {
        if (!is_string($v) || !preg_match('/^\d{4}$/', $v)) {
            return null;
        }

        return (int) $v;
    }

    private static function asMonth(mixed $v): ?int
    {
        if (!is_string($v) || !preg_match('/^\d{1,2}$/', $v)) {
            return null;
        }
        $n = (int) $v;

        return $n >= 1 && $n <= 12 ? $n : null;
    }

    private static function asDay(mixed $v): ?int
    {
        if (!is_string($v) || !preg_match('/^\d{1,2}$/', $v)) {
            return null;
        }
        $n = (int) $v;

        return $n >= 1 && $n <= 31 ? $n : null;
    }
}
