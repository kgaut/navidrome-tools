<?php

namespace App\Controller;

use App\Entity\RunHistory;
use App\LastFm\LastFmAuthService;
use App\LastFm\LovedStarredSyncService;
use App\LastFm\SyncReport;
use App\Service\RunHistoryRecorder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LoveSyncController extends AbstractController
{
    public function __construct(
        private readonly LovedStarredSyncService $sync,
        private readonly LastFmAuthService $authService,
        private readonly RunHistoryRecorder $recorder,
    ) {
    }

    #[Route('/lastfm/love-sync', name: 'app_lastfm_love_sync', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $error = null;
        $report = null;
        $direction = (string) $request->request->get('direction', LovedStarredSyncService::DIRECTION_BOTH);
        $dryRun = $request->request->get('dry_run') !== null
            ? (bool) $request->request->get('dry_run')
            : true;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('lastfm-love-sync', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }
            try {
                $report = $this->recorder->record(
                    type: RunHistory::TYPE_LASTFM_LOVE_SYNC,
                    reference: $direction,
                    label: 'Sync loved/star — ' . $direction . ($dryRun ? ' [dry-run]' : ''),
                    action: fn (RunHistory $entry) => $this->sync->sync($direction, $dryRun),
                    extractMetrics: static fn (SyncReport $r) => [
                        'loved' => $r->lovedCount,
                        'starred' => $r->starredCount,
                        'common' => $r->commonCount,
                        'starred_added' => count($r->starredAdded),
                        'loved_added' => count($r->lovedAdded),
                        'unmatched' => count($r->lovedUnmatched),
                        'errors' => count($r->errors),
                        'direction' => $direction,
                        'dry_run' => $dryRun,
                    ],
                );
                $this->addFlash('success', sprintf(
                    '%s : +%d starred, +%d loved, %d non matchés, %d erreurs.',
                    $dryRun ? 'Dry-run' : 'Sync',
                    count($report->starredAdded),
                    count($report->lovedAdded),
                    count($report->lovedUnmatched),
                    count($report->errors),
                ));
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('lastfm/love_sync.html.twig', [
            'auth_configured' => $this->authService->isConfigured(),
            'session_user' => $this->authService->getStoredSessionUser(),
            'report' => $report,
            'direction' => $direction,
            'dry_run' => $dryRun,
            'error' => $error,
        ]);
    }
}
