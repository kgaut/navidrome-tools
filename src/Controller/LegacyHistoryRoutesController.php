<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The listening history pages moved from `/lastfm/…` to `/listens/…` when
 * ListenBrainz became a source (issue #310). Old bookmarks get a permanent
 * redirect, query string kept.
 */
class LegacyHistoryRoutesController extends AbstractController
{
    private const MOVED = [
        '/lastfm/stats' => 'app_lastfm_stats',
        '/lastfm/top-artists' => 'app_lastfm_top_artists',
        '/lastfm/top-albums' => 'app_lastfm_top_albums',
        '/lastfm/top-tracks' => 'app_lastfm_top_tracks',
        '/lastfm/scrobbles' => 'app_lastfm_scrobbles',
        '/lastfm/import' => 'app_lastfm_import',
    ];

    #[Route('/lastfm/stats', name: 'legacy_lastfm_stats', methods: ['GET'])]
    #[Route('/lastfm/top-artists', name: 'legacy_lastfm_top_artists', methods: ['GET'])]
    #[Route('/lastfm/top-albums', name: 'legacy_lastfm_top_albums', methods: ['GET'])]
    #[Route('/lastfm/top-tracks', name: 'legacy_lastfm_top_tracks', methods: ['GET'])]
    #[Route('/lastfm/scrobbles', name: 'legacy_lastfm_scrobbles', methods: ['GET'])]
    #[Route('/lastfm/import', name: 'legacy_lastfm_import', methods: ['GET'])]
    public function redirectMoved(Request $request): RedirectResponse
    {
        $url = $this->generateUrl(self::MOVED[$request->getPathInfo()], $request->query->all());

        return $this->redirect($url, Response::HTTP_MOVED_PERMANENTLY);
    }
}
