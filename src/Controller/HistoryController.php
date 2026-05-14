<?php

namespace App\Controller;

use App\Entity\RunHistory;
use App\Repository\RunHistoryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HistoryController extends AbstractController
{
    #[Route('/history', name: 'app_history')]
    public function index(Request $request, RunHistoryRepository $repo): Response
    {
        $page = max(1, (int) $request->query->get('page', '1'));
        $filters = [
            'type' => $request->query->get('type', ''),
            'status' => $request->query->get('status', ''),
            'q' => $request->query->get('q', ''),
        ];

        $result = $repo->findFilteredPaginated($filters, $page, 25);

        return $this->render('history/index.html.twig', [
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($result['total'] / 25)),
            'filters' => $filters,
        ]);
    }

    #[Route('/history/{id}', name: 'app_history_show', requirements: ['id' => '\d+'])]
    public function show(RunHistory $runHistory): Response
    {
        return $this->render('history/show.html.twig', ['run' => $runHistory]);
    }

    #[Route('/history/{id}/status.json', name: 'app_history_status', requirements: ['id' => '\d+'])]
    public function status(RunHistory $runHistory): JsonResponse
    {
        return new JsonResponse([
            'id' => $runHistory->getId(),
            'status' => $runHistory->getStatus(),
            'label' => $runHistory->getLabel(),
            'metrics' => $runHistory->getMetrics(),
            'message' => $runHistory->getMessage(),
            'duration_ms' => $runHistory->getDurationMs(),
        ]);
    }
}
