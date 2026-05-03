<?php

namespace App\Controller;

use App\Lidarr\LidarrConfig;
use App\Service\DiscoverArtistsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DiscoverController extends AbstractController
{
    public function __construct(
        private readonly DiscoverArtistsService $service,
        private readonly LidarrConfig $lidarrConfig,
    ) {
    }

    #[Route('/discover/artists', name: 'app_discover_artists', methods: ['GET'])]
    public function index(): Response
    {
        $snapshot = $this->service->getCached();

        return $this->render('discover/artists.html.twig', [
            'snapshot' => $snapshot,
            'fresh' => $snapshot !== null && $this->service->isFresh($snapshot),
            'api_key_configured' => $this->service->isApiKeyConfigured(),
            'lidarr_configured' => $this->lidarrConfig->isConfigured(),
            'ttl_hours' => DiscoverArtistsService::TTL_HOURS,
        ]);
    }

    #[Route('/discover/artists/refresh', name: 'app_discover_artists_refresh', methods: ['POST'])]
    public function refresh(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('discover_refresh', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        try {
            $this->service->compute();
            $this->addFlash('success', 'Suggestions Last.fm rafraîchies.');
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Erreur : ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_discover_artists');
    }
}
