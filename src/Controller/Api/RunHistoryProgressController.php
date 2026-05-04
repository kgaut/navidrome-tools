<?php

namespace App\Controller\Api;

use App\Entity\RunHistory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lightweight JSON endpoint polled by the history detail page to refresh
 * the progress bar of an in-flight async run without reloading.
 */
class RunHistoryProgressController extends AbstractController
{
    #[Route('/history/{id}/progress.json', name: 'app_history_progress_json', methods: ['GET'])]
    public function progress(RunHistory $entry): JsonResponse
    {
        return new JsonResponse([
            'id' => $entry->getId(),
            'status' => $entry->getStatus(),
            'in_progress' => $entry->isInProgress(),
            'progress' => $entry->getProgress(),
            'started_at' => $entry->getStartedAt()->format(\DateTimeInterface::ATOM),
            'finished_at' => $entry->getFinishedAt()?->format(\DateTimeInterface::ATOM),
            'duration_ms' => $entry->getDurationMs(),
            'metrics' => $entry->getMetrics(),
            'message' => $entry->getMessage(),
        ]);
    }
}
