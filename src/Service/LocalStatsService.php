<?php

namespace App\Service;

use App\Entity\StatsSnapshot;
use App\Repository\StatsSnapshotRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Computes listening statistics from the local `scrobbles` table and
 * caches the results in `stats_snapshot` (keyed by period string).
 *
 * All reads go to our own SQLite DB (not Navidrome's) so the stats
 * reflect the complete Last.fm history, not just what's been synced
 * to Navidrome.
 */
class LocalStatsService
{
    public const PERIOD_7D = '7d';
    public const PERIOD_30D = '30d';
    public const PERIOD_90D = '90d';
    public const PERIOD_LAST_YEAR = 'last-year';
    public const PERIOD_ALL_TIME = 'all-time';

    public const PERIODS = [
        self::PERIOD_7D => '7 derniers jours',
        self::PERIOD_30D => '30 derniers jours',
        self::PERIOD_90D => '90 derniers jours',
        self::PERIOD_LAST_YEAR => '12 derniers mois',
        self::PERIOD_ALL_TIME => 'Tout',
    ];

    private const TOP_ARTISTS_LIMIT = 25;
    private const TOP_TRACKS_LIMIT = 50;
    private const PLAYS_BY_MONTH_LIMIT = 24;
    private const HEATMAP_DAYS = 365;

    public function __construct(
        private readonly Connection $connection,
        private readonly StatsSnapshotRepository $snapshots,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Compute stats for a period and persist/update the snapshot.
     * Returns the computed data array.
     *
     * @return array<string, mixed>
     */
    public function compute(string $period, ?string $user = null): array
    {
        [$from, $to] = $this->periodBounds($period);

        $data = [
            'period' => $period,
            'user' => $user,
            'computed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'total_plays' => $this->countPlays($from, $to, $user),
            'top_artists' => $this->topArtists($from, $to, $user),
            'top_tracks' => $this->topTracks($from, $to, $user),
            'plays_by_month' => $this->playsByMonth($from, $to, $user),
            'plays_by_dow' => $this->playsByDayOfWeek($from, $to, $user),
        ];

        $key = $this->snapshotKey($period, $user);
        $snapshot = $this->snapshots->findOneByPeriod($key);
        if ($snapshot === null) {
            $snapshot = new StatsSnapshot($key);
            $this->em->persist($snapshot);
        }
        $snapshot->setData($data);
        $this->em->flush();

        return $data;
    }

    /**
     * Return cached data, or compute on the fly if absent.
     *
     * @return array<string, mixed>
     */
    public function get(string $period, ?string $user = null): array
    {
        $snapshot = $this->snapshots->findOneByPeriod($this->snapshotKey($period, $user));
        if ($snapshot !== null) {
            /** @var array<string, mixed> */
            return $snapshot->getData();
        }

        return $this->compute($period, $user);
    }

    private function snapshotKey(string $period, ?string $user): string
    {
        return $user !== null ? "stats-{$period}-{$user}" : "stats-{$period}";
    }

    /** @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable} */
    private function periodBounds(string $period): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return match ($period) {
            self::PERIOD_7D => [$now->modify('-7 days'), $now],
            self::PERIOD_30D => [$now->modify('-30 days'), $now],
            self::PERIOD_90D => [$now->modify('-90 days'), $now],
            self::PERIOD_LAST_YEAR => [$now->modify('-365 days'), $now],
            self::PERIOD_ALL_TIME => [null, null],
            default => throw new \InvalidArgumentException(sprintf('Unknown period "%s"', $period)),
        };
    }

    private function countPlays(?\DateTimeImmutable $from, ?\DateTimeImmutable $to, ?string $user): int
    {
        [$sql, $params] = $this->baseQuery('COUNT(*)', $from, $to, $user);
        return (int) $this->connection->fetchOne($sql, $params);
    }

    /**
     * @return list<array{artist: string, plays: int}>
     */
    private function topArtists(?\DateTimeImmutable $from, ?\DateTimeImmutable $to, ?string $user): array
    {
        [$where, $params] = $this->buildWhere($from, $to, $user);
        $sql = 'SELECT artist, COUNT(*) AS plays FROM scrobbles'
            . ($where !== '' ? ' WHERE ' . $where : '')
            . ' GROUP BY artist ORDER BY plays DESC LIMIT ' . self::TOP_ARTISTS_LIMIT;

        /** @var list<array{artist: string, plays: int}> */
        return $this->connection->fetchAllAssociative($sql, $params);
    }

    /**
     * @return list<array{artist: string, title: string, plays: int}>
     */
    private function topTracks(?\DateTimeImmutable $from, ?\DateTimeImmutable $to, ?string $user): array
    {
        [$where, $params] = $this->buildWhere($from, $to, $user);
        $sql = 'SELECT artist, title, COUNT(*) AS plays FROM scrobbles'
            . ($where !== '' ? ' WHERE ' . $where : '')
            . ' GROUP BY artist, title ORDER BY plays DESC LIMIT ' . self::TOP_TRACKS_LIMIT;

        /** @var list<array{artist: string, title: string, plays: int}> */
        return $this->connection->fetchAllAssociative($sql, $params);
    }

    /**
     * @return list<array{month: string, plays: int}>
     */
    private function playsByMonth(?\DateTimeImmutable $from, ?\DateTimeImmutable $to, ?string $user): array
    {
        [$where, $params] = $this->buildWhere($from, $to, $user);
        $sql = "SELECT strftime('%Y-%m', played_at) AS month, COUNT(*) AS plays FROM scrobbles"
            . ($where !== '' ? ' WHERE ' . $where : '')
            . ' GROUP BY month ORDER BY month DESC LIMIT ' . self::PLAYS_BY_MONTH_LIMIT;

        /** @var list<array{month: string, plays: int}> */
        return array_reverse($this->connection->fetchAllAssociative($sql, $params));
    }

    /**
     * GitHub-style activity heatmap over the last HEATMAP_DAYS, aligned to
     * Monday-starting weeks. Independent of the period selector — always
     * the same rolling window so the visual is stable across nav clicks.
     *
     * Levels (0..4) are bucketed by the quartiles of NON-ZERO days so a
     * single outlier (a 12h playlist marathon) doesn't crush everything
     * else into level 1.
     *
     * @return array{
     *     weeks: list<list<array{date: string, plays: int, level: int}|null>>,
     *     start: string,
     *     end: string,
     *     total: int,
     *     max: int,
     *     thresholds: list<int>,
     * }
     */
    public function heatmap(?string $user = null): array
    {
        $tz = new \DateTimeZone('UTC');
        $today = new \DateTimeImmutable('today', $tz);
        $start = $today->modify(sprintf('-%d days', self::HEATMAP_DAYS));
        // Snap start back to Monday. PHP date('w'): 0=Sun..6=Sat → we want 0=Mon..6=Sun.
        $startDow = ((int) $start->format('w') + 6) % 7;
        $start = $start->modify(sprintf('-%d days', $startDow));

        [$where, $params] = $this->buildWhere($start, $today->modify('+1 day'), $user);
        $sql = "SELECT date(played_at) AS d, COUNT(*) AS n FROM scrobbles"
            . ($where !== '' ? ' WHERE ' . $where : '')
            . ' GROUP BY d';
        /** @var array<string, int> $plays */
        $plays = $this->connection->fetchAllKeyValue($sql, $params);
        $plays = array_map('intval', $plays);

        $thresholds = $this->quartileThresholds(array_values($plays));

        $weeks = [];
        $cursor = $start;
        while ($cursor <= $today) {
            $week = [];
            for ($i = 0; $i < 7; $i++) {
                if ($cursor > $today) {
                    // Right-pad: days after today within the last column.
                    $week[] = null;
                } else {
                    $date = $cursor->format('Y-m-d');
                    $n = $plays[$date] ?? 0;
                    $week[] = [
                        'date' => $date,
                        'plays' => $n,
                        'level' => $this->level($n, $thresholds),
                    ];
                }
                $cursor = $cursor->modify('+1 day');
            }
            $weeks[] = $week;
        }

        return [
            'weeks' => $weeks,
            'start' => $start->format('Y-m-d'),
            'end' => $today->format('Y-m-d'),
            'total' => array_sum($plays),
            'max' => $plays === [] ? 0 : max($plays),
            'thresholds' => $thresholds,
        ];
    }

    /**
     * @param list<int> $values
     * @return list<int>  [q1, q2, q3] of non-zero values, ascending
     */
    private function quartileThresholds(array $values): array
    {
        $nonZero = array_values(array_filter($values, static fn (int $v): bool => $v > 0));
        if ($nonZero === []) {
            return [0, 0, 0];
        }
        sort($nonZero);
        $n = count($nonZero);

        // Pick the value at each quartile boundary. With <4 distinct values
        // the thresholds collapse, which is fine — `level()` just falls
        // through to the next bucket.
        return [
            $nonZero[(int) floor(($n - 1) * 0.25)],
            $nonZero[(int) floor(($n - 1) * 0.50)],
            $nonZero[(int) floor(($n - 1) * 0.75)],
        ];
    }

    /** @param list<int> $thresholds */
    private function level(int $plays, array $thresholds): int
    {
        if ($plays <= 0) {
            return 0;
        }
        if ($plays <= $thresholds[0]) {
            return 1;
        }
        if ($plays <= $thresholds[1]) {
            return 2;
        }
        if ($plays <= $thresholds[2]) {
            return 3;
        }
        return 4;
    }

    /**
     * @return list<array{dow: int, plays: int}>
     */
    private function playsByDayOfWeek(?\DateTimeImmutable $from, ?\DateTimeImmutable $to, ?string $user): array
    {
        [$where, $params] = $this->buildWhere($from, $to, $user);
        $sql = "SELECT CAST(strftime('%w', played_at) AS INTEGER) AS dow, COUNT(*) AS plays FROM scrobbles"
            . ($where !== '' ? ' WHERE ' . $where : '')
            . ' GROUP BY dow ORDER BY dow';

        /** @var list<array{dow: int, plays: int}> */
        return $this->connection->fetchAllAssociative($sql, $params);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function baseQuery(string $select, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to, ?string $user): array
    {
        [$where, $params] = $this->buildWhere($from, $to, $user);
        $sql = "SELECT {$select} FROM scrobbles" . ($where !== '' ? ' WHERE ' . $where : '');
        return [$sql, $params];
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function buildWhere(?\DateTimeImmutable $from, ?\DateTimeImmutable $to, ?string $user): array
    {
        $parts = [];
        $params = [];

        if ($from !== null) {
            $parts[] = 'played_at >= :from';
            $params['from'] = $from->format('Y-m-d H:i:s');
        }
        if ($to !== null) {
            $parts[] = 'played_at <= :to';
            $params['to'] = $to->format('Y-m-d H:i:s');
        }
        if ($user !== null && $user !== '') {
            $parts[] = 'lastfm_user = :user';
            $params['user'] = $user;
        }

        return [implode(' AND ', $parts), $params];
    }
}
