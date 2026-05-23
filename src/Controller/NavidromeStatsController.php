<?php

namespace App\Controller;

use App\Entity\RunHistory;
use App\Navidrome\NavidromeRepository;
use App\Service\NavidromeStatsService;
use App\Service\RunHistoryRecorder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class NavidromeStatsController extends AbstractController
{
    public function __construct(
        private readonly NavidromeStatsService $statsService,
        private readonly NavidromeRepository $navidrome,
        private readonly RunHistoryRecorder $recorder,
    ) {
    }

    #[Route('/navidrome/stats', name: 'app_navidrome_stats', methods: ['GET'])]
    public function index(): Response
    {
        $data = $this->statsService->get();

        return $this->render('navidrome/stats.html.twig', [
            'data' => $data,
            'navidrome_available' => $this->navidrome->isAvailable(),
        ]);
    }

    #[Route('/navidrome/stats/refresh', name: 'app_navidrome_stats_refresh', methods: ['POST'])]
    public function refresh(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('navidrome_stats_refresh', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->navidrome->isAvailable()) {
            $this->addFlash('error', 'Base Navidrome indisponible — impossible de recalculer.');
            return $this->redirectToRoute('app_navidrome_stats');
        }

        try {
            $this->recorder->record(
                type: RunHistory::TYPE_STATS,
                reference: 'navidrome',
                label: 'Navidrome stats compute',
                action: fn () => $this->statsService->compute(),
            );
            $this->addFlash('success', 'Statistiques Navidrome recalculées.');
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Erreur : ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_navidrome_stats');
    }
}
