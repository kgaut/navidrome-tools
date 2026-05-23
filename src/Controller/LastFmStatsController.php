<?php

namespace App\Controller;

use App\Entity\RunHistory;
use App\Service\LastFmStatsService;
use App\Service\RunHistoryRecorder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LastFmStatsController extends AbstractController
{
    public function __construct(
        private readonly LastFmStatsService $statsService,
        private readonly RunHistoryRecorder $recorder,
        private readonly string $defaultUser,
    ) {
    }

    #[Route('/lastfm/stats', name: 'app_lastfm_stats', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->defaultUser !== '' ? $this->defaultUser : null;
        $data = $this->statsService->get($user);

        return $this->render('lastfm/stats.html.twig', [
            'data' => $data,
            'user' => $user,
        ]);
    }

    #[Route('/lastfm/stats/refresh', name: 'app_lastfm_stats_refresh', methods: ['POST'])]
    public function refresh(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('lastfm_stats_refresh', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $user = $this->defaultUser !== '' ? $this->defaultUser : null;

        try {
            $this->recorder->record(
                type: RunHistory::TYPE_STATS,
                reference: 'lastfm',
                label: 'Last.fm stats compute',
                action: fn () => $this->statsService->compute($user),
            );
            $this->addFlash('success', 'Statistiques Last.fm recalculées.');
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Erreur : ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_lastfm_stats');
    }
}
