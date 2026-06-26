<?php

namespace App\Controller;

use App\Entity\ScrobbleSync;
use App\Message\RematchMessage;
use App\Message\SyncNavidromeMessage;
use App\Repository\ScrobbleSyncRepository;
use App\Service\UnmatchedDiagnoser;
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

        $bus->dispatch(new SyncNavidromeMessage(
            limit: max(0, (int) $request->request->get('limit', 0)),
            dryRun: (bool) $request->request->get('dry_run'),
            toleranceSeconds: max(0, (int) $request->request->get('tolerance', 60)),
            autoStop: (bool) $request->request->get('auto_stop'),
        ));

        $this->addFlash('success', 'Sync Navidrome lancé en arrière-plan.');
        return $this->redirectToRoute('app_history');
    }

    #[Route('/navidrome/sync/reset', name: 'app_navidrome_sync_reset', methods: ['POST'])]
    public function reset(Request $request, ScrobbleSyncRepository $syncRepo): Response
    {
        if (!$this->isCsrfTokenValid('navidrome_sync_reset', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $reset = $syncRepo->resetAllToPending(ScrobbleSync::TARGET_NAVIDROME);
        $this->addFlash('success', sprintf('Reset Navidrome : %d ligne(s) remises en pending.', $reset));

        return $this->redirectToRoute('app_navidrome_sync');
    }

    #[Route('/navidrome/rematch', name: 'app_navidrome_rematch', methods: ['POST'])]
    public function rematch(Request $request, MessageBusInterface $bus): Response
    {
        if (!$this->isCsrfTokenValid('navidrome_rematch', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $bus->dispatch(new RematchMessage(
            target: ScrobbleSync::TARGET_NAVIDROME,
            limit: max(0, (int) $request->request->get('limit', 0)),
            dryRun: (bool) $request->request->get('dry_run'),
            autoStop: (bool) $request->request->get('auto_stop'),
        ));

        $this->addFlash('success', 'Rematch Navidrome lancé en arrière-plan.');
        return $this->redirectToRoute('app_history');
    }

    #[Route('/navidrome/unmatched', name: 'app_navidrome_unmatched', methods: ['GET'])]
    public function unmatched(
        Request $request,
        ScrobbleSyncRepository $syncRepo,
        UnmatchedDiagnoser $diagnoser,
    ): Response {
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = 50;
        $filterArtist = trim((string) $request->query->get('artist', ''));
        $filterTitle = trim((string) $request->query->get('title', ''));

        $rows = $syncRepo->aggregateUnmatched(
            target: ScrobbleSync::TARGET_NAVIDROME,
            limit: $perPage,
            offset: ($page - 1) * $perPage,
            filterArtist: $filterArtist !== '' ? $filterArtist : null,
            filterTitle: $filterTitle !== '' ? $filterTitle : null,
        );
        // Per-row diagnosis is scoped to the current page (worst case 50
        // groups → a handful of cheap SQLite probes each). Cached in
        // {@see UnmatchedDiagnoser} would be premature: a user paginating
        // hits each row at most once per visit.
        foreach ($rows as &$row) {
            $row['diagnosis'] = $diagnoser->diagnose(
                (string) ($row['artist'] ?? ''),
                (string) ($row['title'] ?? ''),
            );
        }
        unset($row);
        $total = $syncRepo->countUnmatchedForTarget(ScrobbleSync::TARGET_NAVIDROME);

        return $this->render('navidrome/unmatched.html.twig', [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'filter_artist' => $filterArtist,
            'filter_title' => $filterTitle,
        ]);
    }
}
