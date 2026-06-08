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
 * Top artistes Last.fm. Sans filtre → lecture instantanée depuis
 * `top_artists_alltime` du snapshot stats. Avec filtre date → requête
 * live (la fenêtre réduit la volumétrie). Snapshot manquant → fallback
 * live + bandeau ambre invitant à lancer `app:lastfm:stats:compute`.
 */
class LastFmTopArtistsController extends AbstractController
{
    private const TOP_N = 100;

    public function __construct(
        private readonly string $defaultUser,
    ) {
    }

    #[Route('/lastfm/top-artists', name: 'app_lastfm_top_artists', methods: ['GET'])]
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
            $rows = $stats->topArtistsWithDates($user, $c['year'], $c['month'], $c['day'], self::TOP_N);
        } else {
            $snapshot = $stats->get($user);
            $cached = is_array($snapshot) && isset($snapshot['top_artists_alltime']) && is_array($snapshot['top_artists_alltime'])
                ? $snapshot['top_artists_alltime']
                : null;
            if ($cached !== null) {
                /** @var list<array{artist: string, plays: int, first_played_at: string, last_played_at: string}> $rows */
                $rows = $cached;
                $source = 'snapshot';
                $computedAt = is_string($snapshot['computed_at'] ?? null) ? $snapshot['computed_at'] : null;
            } else {
                $rows = $stats->topArtistsWithDates($user, null, null, null, self::TOP_N);
                $source = 'live_fallback';
            }
        }

        return $this->render('lastfm/top_artists.html.twig', [
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
