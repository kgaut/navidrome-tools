<?php

namespace App\Controller;

use App\Filter\DateCascadeFilter;
use App\Navidrome\NavidromeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Top 100 artists ranked by Navidrome plays, with per-artist first /
 * last play. Filterable on a year / month / day cascade — same UX as
 * `/lastfm/scrobbles`. Reads only the Navidrome `scrobbles` table
 * (read-only).
 */
class NavidromeTopArtistsController extends AbstractController
{
    private const TOP_N = 100;

    #[Route('/navidrome/top-artists', name: 'app_navidrome_top_artists', methods: ['GET'])]
    public function index(Request $request, NavidromeRepository $navidrome): Response
    {
        $cascade = DateCascadeFilter::parse(
            $request->query->get('year'),
            $request->query->get('month'),
            $request->query->get('day'),
        );

        $rows = $navidrome->getTopArtistsWithDates(
            $cascade['year'],
            $cascade['month'],
            $cascade['day'],
            self::TOP_N,
        );

        return $this->render('navidrome/top_artists.html.twig', [
            'rows' => $rows,
            'top_n' => self::TOP_N,
            'available_years' => $navidrome->getAvailableScrobbleYears(),
            'filters' => [
                'year' => $cascade['year'] !== null ? (string) $cascade['year'] : '',
                'month' => $cascade['month'] !== null ? sprintf('%02d', $cascade['month']) : '',
                'day' => $cascade['day'] !== null ? sprintf('%02d', $cascade['day']) : '',
            ],
        ]);
    }
}
