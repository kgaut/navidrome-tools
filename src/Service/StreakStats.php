<?php

namespace App\Service;

/**
 * Day-streak helpers shared by NavidromeStatsService and LastFmStatsService.
 * « Streak » = number of consecutive calendar days carrying at least one play.
 *
 *  - `longest`: the longest such run found anywhere in the input set.
 *  - `current`: the streak that ends on $today (or one that ended yesterday
 *    if no play landed today yet — habit-tracker convention so opening the
 *    page at 9am doesn't break the count).
 */
final class StreakStats
{
    /**
     * @param iterable<string> $days Y-m-d strings, any order, duplicates allowed.
     *
     * @return array{
     *     longest: int,
     *     longest_started_at: ?string,
     *     longest_ended_at: ?string,
     *     current: int,
     *     current_started_at: ?string,
     *     current_ended_at: ?string
     * }
     */
    public static function compute(iterable $days, ?\DateTimeImmutable $today = null): array
    {
        $set = [];
        foreach ($days as $d) {
            $set[$d] = true;
        }
        if ($set === []) {
            return [
                'longest' => 0,
                'longest_started_at' => null,
                'longest_ended_at' => null,
                'current' => 0,
                'current_started_at' => null,
                'current_ended_at' => null,
            ];
        }
        $sorted = array_keys($set);
        sort($sorted);

        $longest = 1;
        $run = 1;
        $prev = null;
        $runStart = $sorted[0];
        $longestStart = $sorted[0];
        $longestEnd = $sorted[0];
        foreach ($sorted as $d) {
            if ($prev === null) {
                $prev = $d;
                $runStart = $d;
                continue;
            }
            $diff = (int) (new \DateTimeImmutable($prev))->diff(new \DateTimeImmutable($d))->days;
            if ($diff === 1) {
                $run++;
            } else {
                $run = 1;
                $runStart = $d;
            }
            // Tie-break: keep the earliest streak of the maximal length (strict >).
            if ($run > $longest) {
                $longest = $run;
                $longestStart = $runStart;
                $longestEnd = $d;
            }
            $prev = $d;
        }

        $today ??= new \DateTimeImmutable('today');
        $cursor = isset($set[$today->format('Y-m-d')])
            ? $today
            : (isset($set[$today->modify('-1 day')->format('Y-m-d')]) ? $today->modify('-1 day') : null);

        $current = 0;
        $endedAt = null;
        $startedAt = null;
        if ($cursor !== null) {
            $endedAt = $cursor->format('Y-m-d');
            while (isset($set[$cursor->format('Y-m-d')])) {
                $current++;
                $startedAt = $cursor->format('Y-m-d');
                $cursor = $cursor->modify('-1 day');
            }
        }

        return [
            'longest' => $longest,
            'longest_started_at' => $longestStart,
            'longest_ended_at' => $longestEnd,
            'current' => $current,
            'current_started_at' => $startedAt,
            'current_ended_at' => $endedAt,
        ];
    }
}
