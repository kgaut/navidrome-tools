<?php

namespace App\Controller;

use App\Service\StatsCompareService;
use App\Service\StatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class StatsCompareController extends AbstractController
{
    #[Route('/stats/compare', name: 'app_stats_compare', methods: ['GET'])]
    public function index(Request $request, StatsCompareService $compareService): Response
    {
        $periods = StatsService::periods();
        $period1 = $this->normalizePeriod($request->query->get('period1'), StatsService::PERIOD_LAST_30D, $periods);
        $period2 = $this->normalizePeriod($request->query->get('period2'), StatsService::PERIOD_LAST_MONTH, $periods);

        $error = null;
        $result = null;
        try {
            $result = $compareService->compare($period1, $period2);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return $this->render('stats/compare.html.twig', [
            'periods' => $periods,
            'period1' => $period1,
            'period2' => $period2,
            'result' => $result,
            'error' => $error,
        ]);
    }

    /**
     * @param array<string, string> $periods
     */
    private function normalizePeriod(mixed $raw, string $default, array $periods): string
    {
        $value = is_string($raw) ? $raw : '';

        return isset($periods[$value]) ? $value : $default;
    }
}
