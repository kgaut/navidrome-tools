<?php

namespace App\Controller;

use App\Filter\DateCascadeFilter;
use App\Repository\ScrobbleRepository;
use App\Service\LastFmStatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LastFmTopAlbumsController extends AbstractController
{
    private const TOP_N = 100;

    public function __construct(
        private readonly string $defaultUser,
    ) {
    }

    #[Route('/lastfm/top-albums', name: 'app_lastfm_top_albums', methods: ['GET'])]
    public function index(
        Request $request,
        LastFmStatsService $stats,
        ScrobbleRepository $scrobbles,
    ): Response {
        $user = $this->defaultUser !== '' ? $this->defaultUser : null;
        $c = DateCascadeFilter::parse(
            $request->query->get('year'),
            $request->query->get('month'),
            $request->query->get('day'),
        );

        $source = 'live';
        $computedAt = null;
        if ($c['year'] !== null) {
            $rows = $stats->topAlbumsWithDates($user, $c['year'], $c['month'], $c['day'], self::TOP_N);
        } else {
            $snapshot = $stats->get($user);
            $cached = is_array($snapshot) && isset($snapshot['top_albums_alltime']) && is_array($snapshot['top_albums_alltime'])
                ? $snapshot['top_albums_alltime']
                : null;
            if ($cached !== null) {
                /** @var list<array{artist: string, album: string, plays: int, first_played_at: string, last_played_at: string}> $rows */
                $rows = $cached;
                $source = 'snapshot';
                $computedAt = is_string($snapshot['computed_at'] ?? null) ? $snapshot['computed_at'] : null;
            } else {
                $rows = $stats->topAlbumsWithDates($user, null, null, null, self::TOP_N);
                $source = 'live_fallback';
            }
        }

        return $this->render('lastfm/top_albums.html.twig', [
            'rows' => $rows,
            'top_n' => self::TOP_N,
            'available_years' => $scrobbles->availableYears($user),
            'filters' => [
                'year' => $c['year'] !== null ? (string) $c['year'] : '',
                'month' => $c['month'] !== null ? sprintf('%02d', $c['month']) : '',
                'day' => $c['day'] !== null ? sprintf('%02d', $c['day']) : '',
            ],
            'source' => $source,
            'computed_at' => $computedAt,
            'compute_command' => 'app:lastfm:stats:compute',
        ]);
    }
}
