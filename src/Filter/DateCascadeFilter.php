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

    /**
     * Produces the SQLite `WHERE` fragment that applies the year / month /
     * day cascade to a timestamp column. Source of truth shared by
     * `LastFmStatsService::topXWithDates()` and
     * `NavidromeRepository::getTopXWithDates()` — both produced the same
     * three-branch code by hand (year → `strftime('%Y', col)`, +month →
     * `strftime('%Y-%m', col)`, +day → `strftime('%Y-%m-%d', col)`).
     *
     * Two SQLite quirks accommodated :
     *  - When the column is a unix epoch (Navidrome `submission_time` is
     *    INTEGER from 0.55+), pass `$unixepoch = true` to interpose the
     *    `'unixepoch'` modifier expected by `strftime`.
     *  - Named params with a prefix avoid collisions with surrounding
     *    `:user` / `:uid` clauses both call sites already carry.
     *
     * Returns `null` (no clause to add) when `$year` is null.
     *
     * @return array{clause: string, paramName: string, paramValue: string}|null
     */
    public static function toSqlClause(
        ?int $year,
        ?int $month,
        ?int $day,
        string $column,
        bool $unixepoch = false,
        string $paramPrefix = 'dc_',
    ): ?array {
        if ($year === null) {
            return null;
        }

        $strftimeArgs = $unixepoch ? sprintf("%s, 'unixepoch'", $column) : $column;

        if ($day !== null && $month !== null) {
            return [
                'clause' => sprintf("strftime('%%Y-%%m-%%d', %s) = :%symd", $strftimeArgs, $paramPrefix),
                'paramName' => $paramPrefix . 'ymd',
                'paramValue' => sprintf('%04d-%02d-%02d', $year, $month, $day),
            ];
        }
        if ($month !== null) {
            return [
                'clause' => sprintf("strftime('%%Y-%%m', %s) = :%sym", $strftimeArgs, $paramPrefix),
                'paramName' => $paramPrefix . 'ym',
                'paramValue' => sprintf('%04d-%02d', $year, $month),
            ];
        }

        return [
            'clause' => sprintf("strftime('%%Y', %s) = :%sy", $strftimeArgs, $paramPrefix),
            'paramName' => $paramPrefix . 'y',
            'paramValue' => (string) $year,
        ];
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
