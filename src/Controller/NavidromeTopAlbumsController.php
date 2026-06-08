<?php

namespace App\Controller;

use App\Filter\DateCascadeFilter;
use App\Navidrome\NavidromeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class NavidromeTopAlbumsController extends AbstractController
{
    private const TOP_N = 100;

    #[Route('/navidrome/top-albums', name: 'app_navidrome_top_albums', methods: ['GET'])]
    public function index(Request $request, NavidromeRepository $navidrome): Response
    {
        $c = DateCascadeFilter::parse(
            $request->query->get('year'),
            $request->query->get('month'),
            $request->query->get('day'),
        );

        return $this->render('navidrome/top_albums.html.twig', [
            'rows' => $navidrome->getTopAlbumsWithDates($c['year'], $c['month'], $c['day'], self::TOP_N),
            'top_n' => self::TOP_N,
            'available_years' => $navidrome->getAvailableScrobbleYears(),
            'filters' => [
                'year' => $c['year'] !== null ? (string) $c['year'] : '',
                'month' => $c['month'] !== null ? sprintf('%02d', $c['month']) : '',
                'day' => $c['day'] !== null ? sprintf('%02d', $c['day']) : '',
            ],
        ]);
    }
}
