<?php

namespace App\Controller;

use App\Navidrome\NavidromeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ForgottenArtistsController extends AbstractController
{
    private const DEFAULT_MIN_PLAYS = 50;
    private const DEFAULT_IDLE_MONTHS = 12;
    private const ALLOWED_IDLE_MONTHS = [6, 12, 18, 24, 36, 60];

    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly string $navidromeUrl,
    ) {
    }

    #[Route('/stats/forgotten-artists', name: 'app_stats_forgotten_artists', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $minPlays = max(1, (int) $request->query->get('min_plays', (string) self::DEFAULT_MIN_PLAYS));
        $idleMonths = (int) $request->query->get('idle_months', (string) self::DEFAULT_IDLE_MONTHS);
        if (!in_array($idleMonths, self::ALLOWED_IDLE_MONTHS, true)) {
            $idleMonths = self::DEFAULT_IDLE_MONTHS;
        }

        $hasScrobbles = $this->navidrome->isAvailable() && $this->navidrome->hasScrobblesTable();
        $artists = $hasScrobbles
            ? $this->navidrome->getForgottenArtists($minPlays, $idleMonths)
            : [];

        return $this->render('stats/forgotten_artists.html.twig', [
            'has_scrobbles' => $hasScrobbles,
            'artists' => $artists,
            'min_plays' => $minPlays,
            'idle_months' => $idleMonths,
            'allowed_idle_months' => self::ALLOWED_IDLE_MONTHS,
            'navidrome_url' => rtrim($this->navidromeUrl, '/'),
        ]);
    }
}
