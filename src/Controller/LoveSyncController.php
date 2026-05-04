<?php

namespace App\Controller;

use App\Entity\RunHistory;
use App\LastFm\LastFmAuthService;
use App\LastFm\LovedStarredSyncService;
use App\Message\RunLastFmSyncLovedMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

class LoveSyncController extends AbstractController
{
    public function __construct(
        private readonly LastFmAuthService $authService,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
    ) {
    }

    #[Route('/lastfm/love-sync', name: 'app_lastfm_love_sync', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $error = null;
        $direction = (string) $request->request->get('direction', LovedStarredSyncService::DIRECTION_BOTH);
        $dryRun = $request->request->get('dry_run') !== null
            ? (bool) $request->request->get('dry_run')
            : true;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('lastfm-love-sync', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }

            $entry = new RunHistory(
                type: RunHistory::TYPE_LASTFM_LOVE_SYNC,
                reference: $direction,
                label: 'Sync loved/star — ' . $direction . ($dryRun ? ' [dry-run]' : ''),
            );
            $entry->setStatus(RunHistory::STATUS_QUEUED);
            $this->em->persist($entry);
            $this->em->flush();

            $this->bus->dispatch(new RunLastFmSyncLovedMessage(
                runHistoryId: (int) $entry->getId(),
                direction: $direction,
                dryRun: $dryRun,
            ));

            $this->addFlash('success', 'Synchronisation loved/star mise en file — la progression s\'affiche ci-dessous.');

            return new RedirectResponse($this->generateUrl('app_history_detail', ['id' => $entry->getId()]));
        }

        return $this->render('lastfm/love_sync.html.twig', [
            'auth_configured' => $this->authService->isConfigured(),
            'session_user' => $this->authService->getStoredSessionUser(),
            'report' => null,
            'direction' => $direction,
            'dry_run' => $dryRun,
            'error' => $error,
        ]);
    }
}
