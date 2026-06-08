<?php

namespace App\Controller;

use App\Controller\TopList\AbstractTopListController;
use App\Navidrome\NavidromeRepository;
use App\Service\NavidromeStatsService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class NavidromeTopArtistsController extends AbstractTopListController
{
    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly NavidromeStatsService $stats,
    ) {
    }

    #[Route('/navidrome/top-artists', name: 'app_navidrome_top_artists', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->renderTopList($request);
    }

    protected function fetchLive(?int $year, ?int $month, ?int $day, int $limit): array
    {
        return $this->navidrome->getTopArtistsWithDates($year, $month, $day, $limit);
    }

    protected function fetchSnapshot(): ?array
    {
        return $this->stats->get();
    }

    protected function snapshotKey(): string
    {
        return 'top_artists_alltime';
    }

    protected function availableYears(): array
    {
        return $this->navidrome->getAvailableScrobbleYears();
    }

    protected function templateName(): string
    {
        return 'navidrome/top_artists.html.twig';
    }

    protected function computeCommand(): string
    {
        return 'app:navidrome:stats:compute';
    }
}
