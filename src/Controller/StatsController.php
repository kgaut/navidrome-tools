<?php

namespace App\Controller;

use App\Navidrome\NavidromeRepository;
use App\Service\StatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class StatsController extends AbstractController
{
    #[Route('/stats', name: 'app_stats', methods: ['GET'])]
    public function index(Request $request, StatsService $stats, NavidromeRepository $navidrome): Response
    {
        $period = (string) $request->query->get('period', StatsService::PERIOD_ALL_TIME);
        if (!isset(StatsService::periods()[$period])) {
            $period = StatsService::PERIOD_ALL_TIME;
        }

        $clients = $navidrome->isAvailable() ? $navidrome->listScrobbleClients() : [];
        $client = (string) $request->query->get('client', '');
        if ($client !== '' && !in_array($client, $clients, true)) {
            $client = '';
        }
        $clientFilter = $client !== '' ? $client : null;

        $snapshot = $stats->getCached($period, $clientFilter);
        $stale = $snapshot === null;

        return $this->render('stats/index.html.twig', [
            'period' => $period,
            'periods' => StatsService::periods(),
            'snapshot' => $snapshot,
            'stale' => $stale,
            'clients' => $clients,
            'client' => $client,
        ]);
    }

    #[Route('/stats/refresh', name: 'app_stats_refresh', methods: ['POST'])]
    public function refresh(Request $request, StatsService $stats, NavidromeRepository $navidrome): Response
    {
        $period = (string) $request->request->get('period', StatsService::PERIOD_ALL_TIME);
        if (!isset(StatsService::periods()[$period])) {
            $period = StatsService::PERIOD_ALL_TIME;
        }

        $client = (string) $request->request->get('client', '');
        $clients = $navidrome->isAvailable() ? $navidrome->listScrobbleClients() : [];
        if ($client !== '' && !in_array($client, $clients, true)) {
            $client = '';
        }
        $clientFilter = $client !== '' ? $client : null;

        $tokenId = 'stats_refresh_' . StatsService::cacheKey($period, $clientFilter);
        if (!$this->isCsrfTokenValid($tokenId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        try {
            $stats->compute($period, $clientFilter);
            $this->addFlash('success', sprintf(
                'Statistiques recalculées pour « %s »%s.',
                StatsService::periods()[$period],
                $clientFilter !== null ? ' (client : ' . $clientFilter . ')' : '',
            ));
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Erreur : ' . $e->getMessage());
        }

        $params = ['period' => $period];
        if ($client !== '') {
            $params['client'] = $client;
        }

        return $this->redirectToRoute('app_stats', $params);
    }
}
