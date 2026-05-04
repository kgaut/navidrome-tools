<?php

namespace App\Controller;

use App\Docker\NavidromeContainerManager;
use App\Lidarr\LidarrConfig;
use App\Repository\LastFmBufferedScrobbleRepository;
use App\Repository\LastFmImportTrackRepository;
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
            'buffer_count' => $this->bufferRepo->countAll(),
            'lidarr_configured' => $this->lidarrConfig->isConfigured(),
            'navidrome_url' => rtrim($this->navidromeUrl, '/'),
            'container_configured' => $this->containerManager->isConfigured(),
            'container_status' => $containerStatus->value,
            'default_lastfm_user' => $defaultUser,
        ]);
    }
}
