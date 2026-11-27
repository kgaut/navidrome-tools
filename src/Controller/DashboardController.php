<?php

namespace App\Controller;

use App\Docker\NavidromeContainerManager;
use App\Entity\ScrobbleSync;
use App\Repository\RunHistoryRepository;
use App\Repository\ScrobbleRepository;
use App\Repository\ScrobbleSyncRepository;
use App\Scrobble\ScrobbleFetchRunner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard')]
    public function index(
        RunHistoryRepository $runHistory,
        ScrobbleRepository $scrobbleRepo,
        ScrobbleSyncRepository $syncRepo,
        NavidromeContainerManager $container,
        ScrobbleFetchRunner $fetchRunner,
    ): Response {
        $recentRuns = $runHistory->findFilteredPaginated([], 1, 10)['items'];


        $containerStatus = $container->getStatus();

        $navidromePending = $syncRepo->countPendingForTarget(ScrobbleSync::TARGET_NAVIDROME);
        $navidromeUnmatched = $syncRepo->countUnmatchedForTarget(ScrobbleSync::TARGET_NAVIDROME);
        $navidromeMatched = $syncRepo->countByTargetStatus(ScrobbleSync::TARGET_NAVIDROME, ScrobbleSync::STATUS_MATCHED);

        return $this->render('dashboard/index.html.twig', [
            'recent_runs' => $recentRuns,
            'total_scrobbles' => $scrobbleRepo->countAll(),
            'last_fetch' => $fetchRunner->lastFetchAt(),
            'container_configured' => $container->isConfigured(),
            'container_status' => $containerStatus->value,
            'container_label' => $containerStatus->label(),
            'navidrome_pending' => $navidromePending,
            'navidrome_unmatched' => $navidromeUnmatched,
            'navidrome_matched' => $navidromeMatched,
        ]);
    }
}
