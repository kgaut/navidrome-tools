<?php

namespace App\Controller;

use App\Docker\NavidromeContainerManager;
use App\Entity\ScrobbleSync;
use App\Repository\RunHistoryRepository;
use App\Repository\ScrobbleRepository;
use App\Repository\ScrobbleSyncRepository;
use App\Repository\SettingRepository;
use App\Strawberry\StrawberryRepository;
use App\Strawberry\StrawberryUploadService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    public function __construct(private readonly string $defaultUser)
    {
    }

    #[Route('/', name: 'app_dashboard')]
    public function index(
        RunHistoryRepository $runHistory,
        ScrobbleRepository $scrobbleRepo,
        ScrobbleSyncRepository $syncRepo,
        StrawberryRepository $strawberry,
        StrawberryUploadService $uploadService,
        NavidromeContainerManager $container,
        SettingRepository $settings,
    ): Response {
        $recentRuns = $runHistory->findFilteredPaginated([], 1, 10)['items'];

        $user = $this->defaultUser !== '' ? $this->defaultUser : null;
        $lastFetch = $user !== null ? $settings->get('lastfm_last_fetch_' . $user) : '';

        $containerStatus = $container->getStatus();

        $navidromePending = $syncRepo->countPendingForTarget(ScrobbleSync::TARGET_NAVIDROME);
        $navidromeUnmatched = $syncRepo->countUnmatchedForTarget(ScrobbleSync::TARGET_NAVIDROME);
        $navidromeMatched = $syncRepo->countByTargetStatus(ScrobbleSync::TARGET_NAVIDROME, ScrobbleSync::STATUS_MATCHED);

        $strawberryActive = $strawberry->isAvailable() || $uploadService->hasUpload();
        $strawberryPending = $strawberryActive ? $syncRepo->countPendingForTarget(ScrobbleSync::TARGET_STRAWBERRY) : null;
        $strawberryUnmatched = $strawberryActive ? $syncRepo->countUnmatchedForTarget(ScrobbleSync::TARGET_STRAWBERRY) : null;

        return $this->render('dashboard/index.html.twig', [
            'recent_runs' => $recentRuns,
            'total_scrobbles' => $scrobbleRepo->countAll(),
            'last_fetch' => $lastFetch !== '' ? new \DateTimeImmutable($lastFetch) : null,
            'container_configured' => $container->isConfigured(),
            'container_status' => $containerStatus->value,
            'container_label' => $containerStatus->label(),
            'navidrome_pending' => $navidromePending,
            'navidrome_unmatched' => $navidromeUnmatched,
            'navidrome_matched' => $navidromeMatched,
            'strawberry_active' => $strawberryActive,
            'strawberry_pending' => $strawberryPending,
            'strawberry_unmatched' => $strawberryUnmatched,
        ]);
    }
}
