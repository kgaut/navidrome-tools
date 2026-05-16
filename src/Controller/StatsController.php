<?php

namespace App\Controller;

use App\Entity\RunHistory;
use App\Repository\ScrobbleRepository;
use App\Service\LocalStatsService;
use App\Service\RunHistoryRecorder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class StatsController extends AbstractController
{
    public function __construct(
        private readonly LocalStatsService $statsService,
        private readonly ScrobbleRepository $scrobbleRepo,
        private readonly RunHistoryRecorder $recorder,
        private readonly string $defaultUser,
    ) {
    }

    #[Route('/stats', name: 'app_stats', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $period = $request->query->get('period', LocalStatsService::PERIOD_30D);
        if (!array_key_exists($period, LocalStatsService::PERIODS)) {
            $period = LocalStatsService::PERIOD_30D;
        }

        $user = $this->defaultUser !== '' ? $this->defaultUser : null;
        $data = $this->statsService->get($period, $user);

        return $this->render('stats/index.html.twig', [
            'data' => $data,
            'period' => $period,
            'periods' => LocalStatsService::PERIODS,
            'total_scrobbles' => $this->scrobbleRepo->countAll(),
            'heatmap' => $this->statsService->heatmap($user),
        ]);
    }

    #[Route('/stats/refresh', name: 'app_stats_refresh', methods: ['POST'])]
    public function refresh(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('stats_refresh', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $period = $request->request->get('period', LocalStatsService::PERIOD_30D);
        if (!array_key_exists($period, LocalStatsService::PERIODS)) {
            $period = LocalStatsService::PERIOD_30D;
        }

        $user = $this->defaultUser !== '' ? $this->defaultUser : null;

        try {
            $this->recorder->record(
                type: RunHistory::TYPE_STATS,
                reference: 'local',
                label: sprintf('Stats compute (%s)', $period),
                action: fn () => $this->statsService->compute($period, $user),
            );
            $this->addFlash('success', sprintf('Statistiques rechargées pour « %s ».', LocalStatsService::PERIODS[$period]));
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Erreur : ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_stats', ['period' => $period]);
    }
}
