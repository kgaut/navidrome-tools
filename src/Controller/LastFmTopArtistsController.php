<?php

namespace App\Controller;

use App\Filter\DateCascadeFilter;
use App\Repository\ScrobbleRepository;
use App\Service\LastFmStatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Top 100 artists by Last.fm scrobble volume, with per-artist first /
 * last play. Filterable on a year / month / day cascade — same UX as
 * `/lastfm/scrobbles` and the Navidrome counterpart. Reads only the
 * tools DB.
 */
class LastFmTopArtistsController extends AbstractController
{
    private const TOP_N = 100;

    public function __construct(
        private readonly string $defaultUser,
    ) {
    }

    #[Route('/lastfm/top-artists', name: 'app_lastfm_top_artists', methods: ['GET'])]
    public function index(Request $request, LastFmStatsService $stats, ScrobbleRepository $scrobbles): Response
    {
        $user = $this->defaultUser !== '' ? $this->defaultUser : null;
        $cascade = DateCascadeFilter::parse(
            $request->query->get('year'),
            $request->query->get('month'),
            $request->query->get('day'),
        );

        $rows = $stats->topArtistsWithDates(
            $user,
            $cascade['year'],
            $cascade['month'],
            $cascade['day'],
            self::TOP_N,
        );

        return $this->render('lastfm/top_artists.html.twig', [
            'rows' => $rows,
            'top_n' => self::TOP_N,
            'available_years' => $scrobbles->availableYears($user),
            'filters' => [
                'year' => $cascade['year'] !== null ? (string) $cascade['year'] : '',
                'month' => $cascade['month'] !== null ? sprintf('%02d', $cascade['month']) : '',
                'day' => $cascade['day'] !== null ? sprintf('%02d', $cascade['day']) : '',
            ],
        ]);
    }
}
