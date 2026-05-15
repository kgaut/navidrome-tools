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
