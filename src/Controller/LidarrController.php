<?php

namespace App\Controller;

use App\Lidarr\LidarrConfig;
use App\Service\AddArtistToLidarrService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LidarrController extends AbstractController
{
    public function __construct(
        private readonly LidarrConfig $config,
        private readonly AddArtistToLidarrService $service,
    ) {
    }

    #[Route('/lidarr/add-artist', name: 'app_lidarr_add_artist', methods: ['POST'])]
    public function addArtist(Request $request): Response
    {
        $redirectRunId = (int) $request->request->get('_redirect_run_id', 0);
        $redirectUnmatched = (string) $request->request->get('_redirect_unmatched', '');
        $unmatchedRoute = match ($redirectUnmatched) {
            'artists' => 'app_lastfm_unmatched_artists',
            'albums' => 'app_lastfm_unmatched_albums',
            '1', 'tracks' => 'app_lastfm_unmatched',
            default => null,
        };
        $redirectDiscover = (bool) $request->request->get('_redirect_discover', false);
        if ($redirectRunId > 0) {
            $back = $this->redirectToRoute('app_history_detail', ['id' => $redirectRunId]);
        } elseif ($unmatchedRoute !== null) {
            $back = $this->redirectToRoute($unmatchedRoute);
        } elseif ($redirectDiscover) {
            $back = $this->redirectToRoute('app_discover_artists');
        } else {
            $back = $this->redirectToRoute('app_lastfm_import');
        }

        if (!$this->config->isConfigured()) {
            $this->addFlash('error', 'Lidarr n\'est pas configuré (LIDARR_URL / LIDARR_API_KEY).');

            return $back;
        }

        $artist = trim((string) $request->request->get('artist', ''));
        if ($artist === '') {
            $this->addFlash('error', 'Nom d\'artiste manquant.');

            return $back;
        }

        if (!$this->isCsrfTokenValid('lidarr_add', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        try {
            $result = $this->service->add($artist);
            if ($result['alreadyExists']) {
                $this->addFlash('info', sprintf('« %s » est déjà présent dans Lidarr.', $result['artistName']));
            } else {
                $this->addFlash('success', sprintf('« %s » ajouté à Lidarr (id=%d).', $result['artistName'], $result['id']));
            }
        } catch (\Throwable $e) {
            $this->addFlash('error', sprintf('Lidarr : %s', $e->getMessage()));
        }

        return $back;
    }
}
