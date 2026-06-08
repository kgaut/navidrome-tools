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
 * Top morceaux Last.fm. Deux chemins selon que l'utilisateur a posé
 * un filtre date ou pas :
 *
 *  - **Aucun filtre** → lecture depuis `top_tracks_alltime` du
 *    snapshot persisté par `LastFmStatsService::compute()` (commande
 *    `app:lastfm:stats:compute`). C'est la vue par défaut et la plus
 *    lourde à calculer ; on l'amortit une fois pour toutes au
 *    recompute. Si le snapshot manque (jamais calculé), on tombe sur
 *    un fallback live et on signale l'utilisateur dans le bandeau.
 *  - **Filtre posé** (`year` / `month` / `day`) → requête live. Le
 *    filtre réduit la volumétrie donc la requête est rapide même
 *    sur les grosses bases.
 */
class LastFmTopTracksController extends AbstractController
{
    private const TOP_N = 100;

    public function __construct(
        private readonly string $defaultUser,
    ) {
    }

    #[Route('/lastfm/top-tracks', name: 'app_lastfm_top_tracks', methods: ['GET'])]
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
        $hasFilter = $c['year'] !== null;

        $source = $hasFilter ? 'live' : 'snapshot';
        $computedAt = null;
        if ($hasFilter) {
            $rows = $stats->topTracksWithDates($user, $c['year'], $c['month'], $c['day'], self::TOP_N);
        } else {
            $snapshot = $stats->get($user);
            /** @var list<array{artist: string, title: string, album: ?string, plays: int, first_played_at: string, last_played_at: string}>|null $cached */
            $cached = is_array($snapshot) && isset($snapshot['top_tracks_alltime']) && is_array($snapshot['top_tracks_alltime'])
                ? $snapshot['top_tracks_alltime']
                : null;
            if ($cached !== null) {
                $rows = $cached;
                $computedAt = is_string($snapshot['computed_at'] ?? null) ? $snapshot['computed_at'] : null;
            } else {
                $rows = $stats->topTracksWithDates($user, null, null, null, self::TOP_N);
                $source = 'live_fallback';
            }
        }

        return $this->render('lastfm/top_tracks.html.twig', [
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
