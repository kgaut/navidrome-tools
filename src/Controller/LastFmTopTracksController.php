<?php

namespace App\Controller;

use App\Filter\DateCascadeFilter;
use App\Repository\ScrobbleRepository;
use App\Service\LastFmStatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Top morceaux Last.fm — résultat caché 1h par signature (user + filtres
 * date), parce que la requête GROUP BY (artist, title) + sous-requête pour
 * l'album représentatif sur des centaines de milliers de scrobbles est le
 * point chaud sensible. Le TTL court suffit : un fetch nouveau prend une
 * heure à se voir, ce qui est cohérent avec un palmarès « top ».
 */
class LastFmTopTracksController extends AbstractController
{
    private const TOP_N = 100;
    private const CACHE_TTL_SECONDS = 3600;

    public function __construct(
        private readonly string $defaultUser,
    ) {
    }

    #[Route('/lastfm/top-tracks', name: 'app_lastfm_top_tracks', methods: ['GET'])]
    public function index(
        Request $request,
        LastFmStatsService $stats,
        ScrobbleRepository $scrobbles,
        CacheInterface $cache,
    ): Response {
        $user = $this->defaultUser !== '' ? $this->defaultUser : null;
        $c = DateCascadeFilter::parse(
            $request->query->get('year'),
            $request->query->get('month'),
            $request->query->get('day'),
        );

        $cacheKey = self::cacheKey($user, $c);
        /** @var list<array{artist: string, title: string, album: ?string, plays: int, first_played_at: string, last_played_at: string}> $rows */
        $rows = $cache->get($cacheKey, function (ItemInterface $item) use ($stats, $user, $c): array {
            $item->expiresAfter(self::CACHE_TTL_SECONDS);
            return $stats->topTracksWithDates($user, $c['year'], $c['month'], $c['day'], self::TOP_N);
        });

        return $this->render('lastfm/top_tracks.html.twig', [
            'rows' => $rows,
            'top_n' => self::TOP_N,
            'available_years' => $scrobbles->availableYears($user),
            'filters' => [
                'year' => $c['year'] !== null ? (string) $c['year'] : '',
                'month' => $c['month'] !== null ? sprintf('%02d', $c['month']) : '',
                'day' => $c['day'] !== null ? sprintf('%02d', $c['day']) : '',
            ],
            'cache_ttl_seconds' => self::CACHE_TTL_SECONDS,
        ]);
    }

    /**
     * @param array{year: ?int, month: ?int, day: ?int} $cascade
     */
    private static function cacheKey(?string $user, array $cascade): string
    {
        return sprintf(
            'lastfm_top_tracks.%s.%s.%s.%s',
            md5($user ?? ''),
            $cascade['year'] ?? '_',
            $cascade['month'] ?? '_',
            $cascade['day'] ?? '_',
        );
    }
}
