<?php

namespace App\Controller;

use App\Navidrome\NavidromeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class NavidromeStatsController extends AbstractController
{
    public function __construct(private readonly NavidromeRepository $navidrome)
    {
    }

    #[Route('/navidrome/stats', name: 'app_navidrome_stats', methods: ['GET'])]
    public function index(): Response
    {
        if (!$this->navidrome->isAvailable()) {
            return $this->render('navidrome/stats.html.twig', [
                'unavailable' => true,
            ]);
        }

        try {
            $library = $this->navidrome->getLibraryCounts();
            $starred = $this->navidrome->getStarredCounts();
            $totalPlays = $this->navidrome->getTotalPlays(null, null);
            $distinctPlayed = $this->navidrome->getDistinctTracksPlayed(null, null);
            $recentScrobbles = $this->navidrome->getRecentScrobbles(100);
            $recentStarred = $this->navidrome->getRecentStarredTracks(25);
            $topArtists = $this->navidrome->getTopArtists(null, null, 15);
            $topTracks = $this->navidrome->getTopTracksWithDetails(null, null, 15);
            $topAlbums = $this->navidrome->getTopAlbums(null, null, 15);
            $playsByMonth = $this->navidrome->getPlaysByMonth(12);
        } catch (\Throwable $e) {
            return $this->render('navidrome/stats.html.twig', [
                'unavailable' => true,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->render('navidrome/stats.html.twig', [
            'unavailable' => false,
            'has_scrobbles' => $this->navidrome->hasScrobblesTable(),
            'library' => $library,
            'starred' => $starred,
            'total_plays' => $totalPlays,
            'distinct_played' => $distinctPlayed,
            'recent_scrobbles' => $recentScrobbles,
            'recent_starred' => $recentStarred,
            'top_artists' => $topArtists,
            'top_tracks' => $topTracks,
            'top_albums' => $topAlbums,
            'plays_by_month' => $playsByMonth,
        ]);
    }
}
