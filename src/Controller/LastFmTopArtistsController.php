<?php

namespace App\Controller;

use App\Controller\TopList\AbstractTopListController;
use App\Repository\ScrobbleRepository;
use App\Service\LastFmStatsService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LastFmTopArtistsController extends AbstractTopListController
{
    public function __construct(
        private readonly LastFmStatsService $stats,
        private readonly ScrobbleRepository $scrobbles,
        private readonly string $defaultUser,
    ) {
    }

    #[Route('/lastfm/top-artists', name: 'app_lastfm_top_artists', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->renderTopList($request);
    }

    private function user(): ?string
    {
        return $this->defaultUser !== '' ? $this->defaultUser : null;
    }

    protected function fetchLive(?int $year, ?int $month, ?int $day, int $limit): array
    {
        return $this->stats->topArtistsWithDates($this->user(), $year, $month, $day, $limit);
    }

    protected function fetchSnapshot(): ?array
    {
        return $this->stats->get($this->user());
    }

    protected function snapshotKey(): string
    {
        return 'top_artists_alltime';
    }

    protected function availableYears(): array
    {
        return $this->scrobbles->availableYears($this->user());
    }

    protected function templateName(): string
    {
        return 'lastfm/top_artists.html.twig';
    }

    protected function computeCommand(): string
    {
        return 'app:lastfm:stats:compute';
    }
}
