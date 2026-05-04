<?php

namespace App\Controller;

use App\Navidrome\NavidromeRepository;
use App\Service\TopsService;
use App\Subsonic\SubsonicClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TopsController extends AbstractController
{
    #[Route('/stats/tops', name: 'app_stats_tops', methods: ['GET'])]
    public function index(Request $request, TopsService $tops, NavidromeRepository $navidrome): Response
    {
        [$from, $to] = $this->resolveWindow($request);
        $clients = $navidrome->isAvailable() ? $navidrome->listScrobbleClients() : [];
        $client = (string) $request->query->get('client', '');
        if ($client !== '' && !in_array($client, $clients, true)) {
            $client = '';
        }
        $clientFilter = $client !== '' ? $client : null;

        $snapshot = $tops->getCached($from, $to, $clientFilter);

        return $this->render('stats/tops.html.twig', [
            'from' => $from,
            'to' => $to,
            'client' => $client,
            'clients' => $clients,
            'snapshot' => $snapshot,
            'recent' => $tops->recentSnapshots(10),
            'limits' => [
                'artists' => TopsService::TOP_ARTISTS_LIMIT,
                'albums' => TopsService::TOP_ALBUMS_LIMIT,
                'tracks' => TopsService::TOP_TRACKS_LIMIT,
            ],
        ]);
    }

    #[Route('/stats/tops/refresh', name: 'app_stats_tops_refresh', methods: ['POST'])]
    public function refresh(Request $request, TopsService $tops, NavidromeRepository $navidrome): Response
    {
        [$from, $to] = $this->resolveWindow($request);
        $client = (string) $request->request->get('client', '');
        $clients = $navidrome->isAvailable() ? $navidrome->listScrobbleClients() : [];
        if ($client !== '' && !in_array($client, $clients, true)) {
            $client = '';
        }
        $clientFilter = $client !== '' ? $client : null;

        if (!$this->isCsrfTokenValid($this->csrfId($from, $to, $clientFilter), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        try {
            $tops->compute($from, $to, $clientFilter);
            $this->addFlash('success', sprintf(
                'Tops calculés pour la fenêtre %s → %s%s.',
                $from->format('Y-m-d'),
                $to->format('Y-m-d'),
                $clientFilter !== null ? ' (client : ' . $clientFilter . ')' : '',
            ));
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Erreur : ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_stats_tops', $this->queryParams($from, $to, $client));
    }

    #[Route('/stats/tops/playlist', name: 'app_stats_tops_playlist', methods: ['POST'])]
    public function createPlaylist(Request $request, TopsService $tops, SubsonicClient $subsonic): Response
    {
        [$from, $to] = $this->resolveWindow($request);
        $client = (string) $request->request->get('client', '');
        $clientFilter = $client !== '' ? $client : null;

        if (!$this->isCsrfTokenValid($this->csrfId($from, $to, $clientFilter), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $snapshot = $tops->getCached($from, $to, $clientFilter);
        if ($snapshot === null) {
            $this->addFlash('error', 'Aucun snapshot pour cette fenêtre. Calcule-le d\'abord.');

            return $this->redirectToRoute('app_stats_tops', $this->queryParams($from, $to, $client));
        }

        $count = (int) $request->request->get('count', 50);
        $count = max(1, min($count, TopsService::TOP_TRACKS_LIMIT));

        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') {
            $name = sprintf('Top %d morceaux %s → %s', $count, $from->format('Y-m-d'), $to->format('Y-m-d'));
        }

        $tracks = array_slice($snapshot->getData()['top_tracks'] ?? [], 0, $count);
        $songIds = array_values(array_filter(
            array_map(static fn (array $t) => (string) ($t['id'] ?? ''), $tracks),
            static fn (string $id) => $id !== '',
        ));
        if ($songIds === []) {
            $this->addFlash('error', 'Aucun morceau dans ce snapshot.');

            return $this->redirectToRoute('app_stats_tops', $this->queryParams($from, $to, $client));
        }

        try {
            $subsonic->createPlaylist($name, $songIds);
            $this->addFlash('success', sprintf('Playlist « %s » créée (%d morceaux).', $name, count($songIds)));
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Erreur Subsonic : ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_stats_tops', $this->queryParams($from, $to, $client));
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function resolveWindow(Request $request): array
    {
        $bag = $request->isMethod('POST') ? $request->request : $request->query;
        $fromStr = (string) $bag->get('from', '');
        $toStr = (string) $bag->get('to', '');

        $now = new \DateTimeImmutable('now');
        $from = $fromStr !== '' ? $this->parseDate($fromStr) : null;
        $to = $toStr !== '' ? $this->parseDate($toStr) : null;

        if ($from === null) {
            $from = $now->modify('-30 days');
        }
        if ($to === null) {
            $to = $now;
        }
        if ($to < $from) {
            $to = $from;
        }

        return TopsService::normalizeWindow($from, $to);
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    private function queryParams(\DateTimeImmutable $from, \DateTimeImmutable $to, string $client): array
    {
        $params = [
            'from' => $from->format('Y-m-d'),
            'to' => $to->modify('-1 day')->format('Y-m-d'),
        ];
        if ($client !== '') {
            $params['client'] = $client;
        }

        return $params;
    }

    private function csrfId(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $client): string
    {
        return sprintf('tops_%d_%d_%s', $from->getTimestamp(), $to->getTimestamp(), $client ?? '');
    }
}
