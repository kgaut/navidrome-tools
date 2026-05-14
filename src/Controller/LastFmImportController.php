<?php

namespace App\Controller;

use App\Docker\NavidromeContainerManager;
use App\Lidarr\LidarrConfig;
use App\Repository\LastFmBufferedScrobbleRepository;
use App\Repository\LastFmImportTrackRepository;
use App\Strawberry\StrawberryRepository;
use App\Strawberry\StrawberryUploadService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LastFmImportController extends AbstractController
{
    public function __construct(
        private readonly LidarrConfig $lidarrConfig,
        private readonly LastFmImportTrackRepository $trackRepo,
        private readonly LastFmBufferedScrobbleRepository $bufferRepo,
        private readonly NavidromeContainerManager $containerManager,
        private readonly StrawberryRepository $strawberry,
        private readonly StrawberryUploadService $strawberryUpload,
        private readonly string $navidromeUrl,
    ) {
    }

    #[Route('/lastfm/import', name: 'app_lastfm_import', methods: ['GET'])]
    public function index(): Response
    {
        $containerStatus = $this->containerManager->getStatus();
        $defaultUser = (string) ($_ENV['LASTFM_USER'] ?? getenv('LASTFM_USER') ?: '');

        return $this->render('lastfm/import.html.twig', [
            'unmatched_cumulative' => $this->trackRepo->countUnmatched(),
            'buffer_count' => $this->bufferRepo->countUnsyncedNavidrome(),
            'buffer_unsynced_strawberry' => $this->strawberry->isAvailable() ? $this->bufferRepo->countUnsyncedStrawberry() : null,
            'buffer_pending_strawberry' => $this->strawberry->isAvailable() ? $this->bufferRepo->countPendingStrawberry() : null,
            'buffer_unmatched_strawberry' => $this->strawberry->isAvailable() ? $this->bufferRepo->countUnmatchedStrawberry() : null,
            'strawberry_configured' => $this->strawberry->isAvailable(),
            'strawberry_upload_info' => $this->strawberryUpload->getUploadInfo(),
            'strawberry_upload_unsynced' => $this->strawberryUpload->hasUpload() ? $this->bufferRepo->countPendingStrawberry() : null,
            'strawberry_upload_unmatched' => $this->strawberryUpload->hasUpload() ? $this->bufferRepo->countUnmatchedStrawberry() : null,
            'lidarr_configured' => $this->lidarrConfig->isConfigured(),
            'navidrome_url' => rtrim($this->navidromeUrl, '/'),
            'container_configured' => $this->containerManager->isConfigured(),
            'container_status' => $containerStatus->value,
            'default_lastfm_user' => $defaultUser,
        ]);
    }
}
