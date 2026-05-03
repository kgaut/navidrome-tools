<?php

namespace App\Controller;

use App\Navidrome\NavidromeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class IncompleteMetadataController extends AbstractController
{
    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly string $navidromeUrl,
    ) {
    }

    #[Route('/stats/incomplete-metadata', name: 'app_stats_incomplete_metadata', methods: ['GET'])]
    public function index(): Response
    {
        $available = $this->navidrome->isAvailable();
        $albums = $available ? $this->navidrome->getIncompleteAlbums(200) : [];

        // Group by album_artist (preserves the ORDER BY plays DESC of the SQL)
        // Each group keeps the order of the first album seen, and tracks
        // a running total of plays — used to rank artists.
        $byArtist = [];
        foreach ($albums as $a) {
            $artist = $a['album_artist'] !== '' ? $a['album_artist'] : '(artiste inconnu)';
            if (!isset($byArtist[$artist])) {
                $byArtist[$artist] = ['artist' => $artist, 'total_plays' => 0, 'albums' => []];
            }
            $byArtist[$artist]['total_plays'] += $a['plays'];
            $byArtist[$artist]['albums'][] = $a;
        }

        // Re-sort artists by total plays descending (the SQL gave us album-level
        // sort, but artists with several low-play albums beat single-album
        // artists with one big-play album once we aggregate).
        uasort($byArtist, static fn (array $a, array $b): int => $b['total_plays'] <=> $a['total_plays']);

        return $this->render('stats/incomplete_metadata.html.twig', [
            'available' => $available,
            'by_artist' => $byArtist,
            'total_albums' => count($albums),
            'navidrome_url' => rtrim($this->navidromeUrl, '/'),
        ]);
    }
}
