<?php

namespace App\Controller;

use App\Lidarr\LidarrClient;
use App\Lidarr\LidarrConfig;
use App\Repository\LastFmImportTrackRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LastFmUnmatchedController extends AbstractController
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly LastFmImportTrackRepository $trackRepo,
        private readonly LidarrConfig $lidarrConfig,
        private readonly LidarrClient $lidarrClient,
    ) {
    }

    #[Route('/lastfm/unmatched', name: 'app_lastfm_unmatched', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filters = [
            'artist' => trim((string) $request->query->get('artist', '')) ?: null,
            'title' => trim((string) $request->query->get('title', '')) ?: null,
            'album' => trim((string) $request->query->get('album', '')) ?: null,
        ];
        $page = max(1, (int) $request->query->get('page', 1));

        $result = $this->trackRepo->findUnmatchedAggregated(
            artist: $filters['artist'],
            title: $filters['title'],
            album: $filters['album'],
            page: $page,
            perPage: self::PER_PAGE,
        );
        $totalPages = (int) ceil(max(1, $result['total']) / self::PER_PAGE);

        $lidarrConfigured = $this->lidarrConfig->isConfigured();
        $lidarrReachable = true;
        $lidarrIndex = null;
        if ($lidarrConfigured) {
            try {
                $lidarrIndex = $this->lidarrClient->indexExistingArtists();
            } catch (\Throwable) {
                $lidarrReachable = false;
            }
        }

        $rows = [];
        foreach ($result['items'] as $r) {
            $key = mb_strtolower(trim($r['artist']));
            $match = $lidarrIndex[$key] ?? null;
            $rows[] = [
                'artist' => $r['artist'],
                'title' => $r['title'],
                'album' => $r['album'],
                'scrobbles' => $r['scrobbles'],
                'last_played' => $r['last_played'],
                'lidarr_url' => $match !== null && $match['foreignArtistId'] !== ''
                    ? $this->lidarrConfig->artistDetailUrl($match['foreignArtistId'])
                    : null,
            ];
        }

        return $this->render('lastfm/unmatched.html.twig', [
            'rows' => $rows,
            'total' => $result['total'],
            'page' => $page,
            'total_pages' => $totalPages,
            'filters' => $filters,
            'lidarr_configured' => $lidarrConfigured,
            'lidarr_reachable' => $lidarrReachable,
        ]);
    }
}
