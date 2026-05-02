<?php

namespace App\Controller;

use App\Navidrome\NavidromeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class StatsChartsController extends AbstractController
{
    private const ALLOWED_MONTHS = [12, 24, 36, 60];

    private const DAY_LABELS = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];

    /**
     * Stable 5-color palette for the top-artists chart. Shared between
     * the JS dataset config (canvas line colors) and the custom HTML
     * legend so the dot next to each artist photo always matches the
     * line on the chart.
     */
    private const TOP_ARTISTS_PALETTE = ['#ef4444', '#3b82f6', '#10b981', '#f59e0b', '#8b5cf6'];

    #[Route('/stats/charts', name: 'app_stats_charts', methods: ['GET'])]
    public function index(Request $request, NavidromeRepository $navidrome): Response
    {
        $months = $this->normalizeMonths($request->query->get('months'));

        $hasScrobbles = $navidrome->isAvailable() && $navidrome->hasScrobblesTable();

        $playsByMonth = $hasScrobbles ? $navidrome->getPlaysByMonth($months) : [];
        $topArtists = $hasScrobbles ? $navidrome->getTopArtistsTimeline($months, 5) : [];

        $heatmap = $hasScrobbles
            ? $navidrome->getHeatmapDayHour((new \DateTimeImmutable())->modify('-90 days'), null)
            : [];
        $byDow = array_fill(0, 7, 0);
        foreach ($heatmap as $dow => $hours) {
            $byDow[$dow] = array_sum($hours);
        }
        // Re-order Monday-first for the chart (locale-friendly).
        $dayOrder = [1, 2, 3, 4, 5, 6, 0];
        $dayLabels = [];
        $dayValues = [];
        foreach ($dayOrder as $idx) {
            $dayLabels[] = self::DAY_LABELS[$idx];
            $dayValues[] = $byDow[$idx];
        }

        return $this->render('stats/charts.html.twig', [
            'has_scrobbles' => $hasScrobbles,
            'months' => $months,
            'allowed_months' => self::ALLOWED_MONTHS,
            'plays_by_month' => $playsByMonth,
            'top_artists' => $topArtists,
            'top_artists_palette' => self::TOP_ARTISTS_PALETTE,
            'day_labels' => $dayLabels,
            'day_values' => $dayValues,
        ]);
    }

    private function normalizeMonths(mixed $raw): int
    {
        $value = is_string($raw) ? (int) $raw : 0;

        return in_array($value, self::ALLOWED_MONTHS, true) ? $value : 24;
    }
}
