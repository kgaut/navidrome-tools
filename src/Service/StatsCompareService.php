<?php

namespace App\Service;

use App\Navidrome\NavidromeRepository;

class StatsCompareService
{
    private const STATUS_NEW = 'nouveau';
    private const STATUS_GONE = 'disparu';
    private const STATUS_UP = 'up';
    private const STATUS_DOWN = 'down';
    private const STATUS_EQUAL = 'equal';

    public function __construct(private readonly NavidromeRepository $navidrome)
    {
    }

    public function compare(string $period1, string $period2, int $tracksLimit = 20, int $artistsLimit = 10): CompareResult
    {
        $window1 = $this->resolveWindow($period1);
        $window2 = $this->resolveWindow($period2);

        $period1Data = $this->snapshot($period1, $window1);
        $period2Data = $this->snapshot($period2, $window2);

        $artists = $this->mergeArtists(
            $this->navidrome->getTopArtists($window1[0], $window1[1], $artistsLimit * 3),
            $this->navidrome->getTopArtists($window2[0], $window2[1], $artistsLimit * 3),
            $artistsLimit,
        );

        $tracks = $this->mergeTracks(
            $this->navidrome->getTopTracksWithDetails($window1[0], $window1[1], $tracksLimit * 3),
            $this->navidrome->getTopTracksWithDetails($window2[0], $window2[1], $tracksLimit * 3),
            $tracksLimit,
        );

        return new CompareResult($period1Data, $period2Data, $artists, $tracks);
    }

    /**
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    private function resolveWindow(string $period): array
    {
        $now = new \DateTimeImmutable('now');

        return match ($period) {
            StatsService::PERIOD_LAST_7D => [$now->sub(new \DateInterval('P7D')), $now],
            StatsService::PERIOD_LAST_30D => [$now->sub(new \DateInterval('P30D')), $now],
            StatsService::PERIOD_LAST_MONTH => [
                $now->modify('first day of this month')->setTime(0, 0)->modify('-1 month'),
                $now->modify('first day of this month')->setTime(0, 0),
            ],
            StatsService::PERIOD_LAST_YEAR => [
                new \DateTimeImmutable(sprintf('%d-01-01 00:00:00', (int) $now->format('Y') - 1)),
                new \DateTimeImmutable(sprintf('%d-01-01 00:00:00', (int) $now->format('Y'))),
            ],
            StatsService::PERIOD_ALL_TIME => [null, null],
            default => throw new \InvalidArgumentException(sprintf('Unknown period "%s".', $period)),
        };
    }

    /**
     * @param array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable} $window
     *
     * @return array{period: string, label: string, total_plays: int, distinct_tracks: int}
     */
    private function snapshot(string $period, array $window): array
    {
        return [
            'period' => $period,
            'label' => StatsService::periods()[$period] ?? $period,
            'total_plays' => $this->navidrome->getTotalPlays($window[0], $window[1]),
            'distinct_tracks' => $this->navidrome->getDistinctTracksPlayed($window[0], $window[1]),
        ];
    }

    /**
     * @param list<array{artist: string, plays: int}> $top1
     * @param list<array{artist: string, plays: int}> $top2
     *
     * @return list<array{artist: string, plays1: int, plays2: int, delta: int, status: string}>
     */
    private function mergeArtists(array $top1, array $top2, int $limit): array
    {
        $by1 = [];
        foreach ($top1 as $r) {
            $by1[$r['artist']] = $r['plays'];
        }
        $by2 = [];
        foreach ($top2 as $r) {
            $by2[$r['artist']] = $r['plays'];
        }

        $out = [];
        foreach (array_unique(array_merge(array_keys($by1), array_keys($by2))) as $artist) {
            $p1 = $by1[$artist] ?? 0;
            $p2 = $by2[$artist] ?? 0;
            $out[] = [
                'artist' => $artist,
                'plays1' => $p1,
                'plays2' => $p2,
                'delta' => $p2 - $p1,
                'status' => $this->statusFor($p1, $p2),
            ];
        }

        usort($out, static fn (array $a, array $b) => max($b['plays1'], $b['plays2']) <=> max($a['plays1'], $a['plays2']));

        return array_slice($out, 0, $limit);
    }

    /**
     * @param list<array{id: string, title: string, artist: string, album: string, plays: int}> $top1
     * @param list<array{id: string, title: string, artist: string, album: string, plays: int}> $top2
     *
     * @return list<array{id: string, title: string, artist: string, album: string, plays1: int, plays2: int, delta: int, status: string}>
     */
    private function mergeTracks(array $top1, array $top2, int $limit): array
    {
        $byId = [];
        foreach ($top1 as $r) {
            $byId[$r['id']] = ['meta' => $r, 'p1' => $r['plays'], 'p2' => 0];
        }
        foreach ($top2 as $r) {
            if (isset($byId[$r['id']])) {
                $byId[$r['id']]['p2'] = $r['plays'];
            } else {
                $byId[$r['id']] = ['meta' => $r, 'p1' => 0, 'p2' => $r['plays']];
            }
        }

        $out = [];
        foreach ($byId as $row) {
            $meta = $row['meta'];
            $out[] = [
                'id' => $meta['id'],
                'title' => $meta['title'],
                'artist' => $meta['artist'],
                'album' => $meta['album'],
                'plays1' => $row['p1'],
                'plays2' => $row['p2'],
                'delta' => $row['p2'] - $row['p1'],
                'status' => $this->statusFor($row['p1'], $row['p2']),
            ];
        }

        usort($out, static fn (array $a, array $b) => max($b['plays1'], $b['plays2']) <=> max($a['plays1'], $a['plays2']));

        return array_slice($out, 0, $limit);
    }

    private function statusFor(int $p1, int $p2): string
    {
        if ($p1 === 0 && $p2 > 0) {
            return self::STATUS_NEW;
        }
        if ($p1 > 0 && $p2 === 0) {
            return self::STATUS_GONE;
        }
        if ($p2 > $p1) {
            return self::STATUS_UP;
        }
        if ($p2 < $p1) {
            return self::STATUS_DOWN;
        }

        return self::STATUS_EQUAL;
    }
}
