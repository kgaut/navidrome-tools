<?php

namespace App\Controller;

use App\Filter\DateCascadeFilter;
use App\Navidrome\NavidromeRepository;
use App\Service\NavidromeStatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class NavidromeTopAlbumsController extends AbstractController
{
    private const TOP_N = 100;

    #[Route('/navidrome/top-albums', name: 'app_navidrome_top_albums', methods: ['GET'])]
    public function index(Request $request, NavidromeRepository $navidrome, NavidromeStatsService $stats): Response
    {
        $c = DateCascadeFilter::parse(
            $request->query->get('year'),
            $request->query->get('month'),
            $request->query->get('day'),
        );

        $source = 'live';
        $computedAt = null;
        if ($c['year'] !== null) {
            $rows = $navidrome->getTopAlbumsWithDates($c['year'], $c['month'], $c['day'], self::TOP_N);
        } else {
            $snapshot = $stats->get();
            $cached = is_array($snapshot) && isset($snapshot['top_albums_alltime']) && is_array($snapshot['top_albums_alltime'])
                ? $snapshot['top_albums_alltime']
                : null;
            if ($cached !== null) {
                /** @var list<array{album_id: string, album: string, artist: string, plays: int, track_count: int, first_played_at: string, last_played_at: string}> $rows */
                $rows = $cached;
                $source = 'snapshot';
                $computedAt = is_string($snapshot['computed_at'] ?? null) ? $snapshot['computed_at'] : null;
            } else {
                $rows = $navidrome->getTopAlbumsWithDates(null, null, null, self::TOP_N);
                $source = 'live_fallback';
            }
        }

        return $this->render('navidrome/top_albums.html.twig', [
            'rows' => $rows,
            'top_n' => self::TOP_N,
            'available_years' => $navidrome->getAvailableScrobbleYears(),
            'filters' => [
                'year' => $c['year'] !== null ? (string) $c['year'] : '',
                'month' => $c['month'] !== null ? sprintf('%02d', $c['month']) : '',
                'day' => $c['day'] !== null ? sprintf('%02d', $c['day']) : '',
            ],
            'source' => $source,
            'computed_at' => $computedAt,
            'compute_command' => 'app:navidrome:stats:compute',
        ]);
    }
}
