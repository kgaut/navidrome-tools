<?php

namespace App\Controller;

use App\Navidrome\NavidromeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class StatsHeatmapController extends AbstractController
{
    private const MONTH_LABELS = [
        '', 'Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc',
    ];

    private const DAY_LABELS = ['Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'];

    #[Route('/stats/heatmap', name: 'app_stats_heatmap', methods: ['GET'])]
    public function index(Request $request, NavidromeRepository $navidrome): Response
    {
        $hasScrobbles = $navidrome->isAvailable() && $navidrome->hasScrobblesTable();
        $now = new \DateTimeImmutable();
        $year = (int) ($request->query->get('year') ?? $now->format('Y'));
        if ($year < 2000 || $year > (int) $now->format('Y') + 1) {
            $year = (int) $now->format('Y');
        }

        // Day×hour heatmap on the last 90 days
        $matrix = $hasScrobbles
            ? $navidrome->getHeatmapDayHour($now->modify('-90 days'), null)
            : [];
        $maxHour = 0;
        foreach ($matrix as $row) {
            foreach ($row as $v) {
                if ($v > $maxHour) {
                    $maxHour = $v;
                }
            }
        }

        // Year × day heatmap
        $daily = $hasScrobbles ? $navidrome->getDailyPlays($year) : [];
        $maxDaily = $daily === [] ? 0 : max($daily);
        $weeks = $this->buildYearGrid($year, $daily);

        $availableYears = range((int) $now->format('Y'), max(2000, (int) $now->format('Y') - 9));

        return $this->render('stats/heatmap.html.twig', [
            'has_scrobbles' => $hasScrobbles,
            'matrix' => $matrix,
            'max_hour' => $maxHour,
            'day_labels' => self::DAY_LABELS,
            'year' => $year,
            'available_years' => $availableYears,
            'weeks' => $weeks,
            'max_daily' => $maxDaily,
            'month_labels' => self::MONTH_LABELS,
        ]);
    }

    /**
     * Build a list of weeks; each week is a list of 7 cells (Sun-first to mirror
     * GitHub-style grid). Cells outside the year are nulls.
     *
     * @param array<string, int> $daily
     *
     * @return list<array{week_start: string, cells: list<?array{date: string, plays: int}>}>
     */
    private function buildYearGrid(int $year, array $daily): array
    {
        $start = new \DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $year));
        $end = new \DateTimeImmutable(sprintf('%04d-01-01 00:00:00', $year + 1));

        // Walk back to the previous Sunday so the first column is aligned.
        $cursor = $start;
        while ((int) $cursor->format('w') !== 0) {
            $cursor = $cursor->modify('-1 day');
        }

        $weeks = [];
        while ($cursor < $end) {
            $cells = [];
            $weekStart = $cursor;
            for ($d = 0; $d < 7; $d++) {
                if ($cursor < $start || $cursor >= $end) {
                    $cells[] = null;
                } else {
                    $key = $cursor->format('Y-m-d');
                    $cells[] = ['date' => $key, 'plays' => $daily[$key] ?? 0];
                }
                $cursor = $cursor->modify('+1 day');
            }
            $weeks[] = ['week_start' => $weekStart->format('Y-m-d'), 'cells' => $cells];
        }

        return $weeks;
    }
}
