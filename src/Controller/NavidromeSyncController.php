<?php

namespace App\Controller;

use App\Entity\ScrobbleSync;
use App\Message\SyncNavidromeMessage;
use App\Repository\ScrobbleSyncRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

class NavidromeSyncController extends AbstractController
{
    #[Route('/navidrome/sync', name: 'app_navidrome_sync', methods: ['GET'])]
    public function index(ScrobbleSyncRepository $syncRepo): Response
    {
        return $this->render('navidrome/sync.html.twig', [
            'pending' => $syncRepo->countPendingForTarget(ScrobbleSync::TARGET_NAVIDROME),
            'matched' => $syncRepo->countByTargetStatus(ScrobbleSync::TARGET_NAVIDROME, ScrobbleSync::STATUS_MATCHED),
            'unmatched' => $syncRepo->countByTargetStatus(ScrobbleSync::TARGET_NAVIDROME, ScrobbleSync::STATUS_UNMATCHED),
            'duplicates' => $syncRepo->countByTargetStatus(ScrobbleSync::TARGET_NAVIDROME, ScrobbleSync::STATUS_DUPLICATE),
        ]);
    }

    #[Route('/navidrome/sync', name: 'app_navidrome_sync_post', methods: ['POST'])]
    public function sync(Request $request, MessageBusInterface $bus): Response
    {
        if (!$this->isCsrfTokenValid('navidrome_sync', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $dryRun = (bool) $request->request->get('dry_run');
        $autoStop = (bool) $request->request->get('auto_stop');
        $limit = max(0, (int) $request->request->get('limit', 0));
        $tolerance = max(0, (int) $request->request->get('tolerance', 60));

        $bus->dispatch(new SyncNavidromeMessage(
            limit: $limit,
            dryRun: $dryRun,
            toleranceSeconds: $tolerance,
            autoStop: $autoStop,
        ));

        $this->addFlash('success', 'Sync Navidrome lancé en arrière-plan. Suivez la progression dans l\'historique.');

        return $this->redirectToRoute('app_history');
    }

    #[Route('/navidrome/unmatched', name: 'app_navidrome_unmatched', methods: ['GET'])]
    public function unmatched(Request $request, ScrobbleSyncRepository $syncRepo): Response
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = 50;

        $rows = $syncRepo->aggregateUnmatched(ScrobbleSync::TARGET_NAVIDROME, $perPage, ($page - 1) * $perPage);
        $total = $syncRepo->countUnmatchedForTarget(ScrobbleSync::TARGET_NAVIDROME);

        return $this->render('navidrome/unmatched.html.twig', [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
        ]);
    }
}
