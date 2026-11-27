<?php

namespace App\Controller;

use App\Entity\RunHistory;
use App\Loves\ActiveLovesProvider;
use App\Message\SyncLovedFlagsMessage;
use App\Service\LastFmStatsService;
use App\Service\RunHistoryRecorder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

class LastFmStatsController extends AbstractController
{
    public function __construct(
        private readonly LastFmStatsService $statsService,
        private readonly RunHistoryRecorder $recorder,
        private readonly string $historyUser,
        private readonly ActiveLovesProvider $lovesProviders,
    ) {
    }

    #[Route('/listens/stats', name: 'app_lastfm_stats', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->historyUser !== '' ? $this->historyUser : null;
        $data = $this->statsService->get($user);

        return $this->render('lastfm/stats.html.twig', [
            'data' => $data,
            'user' => $user,
            'can_sync_loved' => $this->canSyncLoved(),
        ]);
    }

    #[Route('/listens/stats/refresh', name: 'app_lastfm_stats_refresh', methods: ['POST'])]
    public function refresh(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('lastfm_stats_refresh', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $user = $this->historyUser !== '' ? $this->historyUser : null;

        try {
            $this->recorder->record(
                type: RunHistory::TYPE_STATS,
                reference: 'lastfm',
                label: 'Listening history stats compute',
                action: fn () => $this->statsService->compute($user),
            );
            $this->addFlash('success', 'Statistiques de l\'historique d\'écoutes recalculées.');
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Erreur : ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_lastfm_stats');
    }

    #[Route('/listens/stats/sync-loved', name: 'app_lastfm_stats_sync_loved', methods: ['POST'])]
    public function syncLoved(Request $request, MessageBusInterface $bus): Response
    {
        if (!$this->isCsrfTokenValid('lastfm_stats_sync_loved', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->canSyncLoved()) {
            $this->addFlash('error', 'Source des écoutes non configurée (SCROBBLE_SOURCE, utilisateur ou clé).');
            return $this->redirectToRoute('app_lastfm_stats');
        }

        $bus->dispatch(new SyncLovedFlagsMessage());

        $this->addFlash('success', 'Sync des coups de cœur lancé en arrière-plan. Pensez à recalculer le snapshot ensuite.');

        return $this->redirectToRoute('app_lastfm_stats');
    }

    private function canSyncLoved(): bool
    {
        try {
            return $this->lovesProviders->get()->isConfigured();
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
