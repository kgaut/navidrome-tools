<?php

namespace App\Service;

use App\Entity\RunHistory;

final class LastFmImportSummary
{
    /**
     * @return array{
     *   fetched: int,
     *   matched: array{n: int, pct: float},
     *   inserted: array{n: int, pct: float},
     *   duplicates: array{n: int, pct: float},
     *   unmatched: array{n: int, pct: float},
     *   skipped: array{n: int, pct: float},
     * }|null
     */
    public static function fromRun(RunHistory $entry): ?array
    {
        if ($entry->getType() !== RunHistory::TYPE_LASTFM_IMPORT) {
            return null;
        }

        $metrics = $entry->getMetrics() ?? [];
        $fetched = self::int($metrics['fetched'] ?? 0);
        $inserted = self::int($metrics['inserted'] ?? 0);
        $duplicates = self::int($metrics['duplicates'] ?? 0);
        $unmatched = self::int($metrics['unmatched'] ?? 0);
        $skipped = self::int($metrics['skipped'] ?? 0);
        $matched = $inserted + $duplicates;

        $pct = static fn (int $n): float => $fetched > 0 ? round($n * 100 / $fetched, 1) : 0.0;

        return [
            'fetched' => $fetched,
            'matched' => ['n' => $matched, 'pct' => $pct($matched)],
            'inserted' => ['n' => $inserted, 'pct' => $pct($inserted)],
            'duplicates' => ['n' => $duplicates, 'pct' => $pct($duplicates)],
            'unmatched' => ['n' => $unmatched, 'pct' => $pct($unmatched)],
            'skipped' => ['n' => $skipped, 'pct' => $pct($skipped)],
        ];
    }

    private static function int(mixed $v): int
    {
        return is_numeric($v) ? (int) $v : 0;
    }
}
