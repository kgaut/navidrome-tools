<?php

namespace App\Controller\Api;

use App\Docker\NavidromeContainerManager;
use App\Navidrome\NavidromeRepository;
use App\Repository\LastFmImportTrackRepository;
use App\Repository\RunHistoryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * JSON status endpoint serving both a no-auth Docker healthcheck and
 * the gethomepage.dev custom-API widget. Without a token, returns a
 * minimal payload (200 healthy / 503 degraded). With a valid token —
 * matched against `HOMEPAGE_API_TOKEN`, supplied via `?token=…` or
 * `Authorization: Bearer …` — returns the enriched payload.
 */
class StatusController extends AbstractController
{
    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly LastFmImportTrackRepository $importTracks,
        private readonly RunHistoryRepository $runHistory,
        private readonly NavidromeContainerManager $navidromeContainer,
        private readonly string $apiToken,
    ) {
    }

    #[Route('/api/status', name: 'app_api_status', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $providedToken = $this->extractToken($request);
        $navidromeOk = $this->navidrome->isAvailable();

        if ($providedToken === null) {
            return $this->basic($navidromeOk);
        }

        if ($this->apiToken === '') {
            return new JsonResponse(['error' => 'Not Found'], Response::HTTP_NOT_FOUND);
        }
        if (!hash_equals($this->apiToken, $providedToken)) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->enriched($navidromeOk);
    }

    private function basic(bool $navidromeOk): JsonResponse
    {
        return new JsonResponse(
            [
                'status' => $navidromeOk ? 'ok' : 'degraded',
                'navidrome_db' => $navidromeOk,
            ],
            $navidromeOk ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    private function enriched(bool $navidromeOk): JsonResponse
    {
        $hasScrobbles = $navidromeOk && $this->navidrome->hasScrobblesTable();
        $lastRun = $this->runHistory->findFilteredPaginated([], 1, 1)['items'][0] ?? null;

        return new JsonResponse([
            'status' => $navidromeOk ? 'ok' : 'degraded',
            'navidrome_db' => $navidromeOk,
            'scrobbles_total' => $hasScrobbles ? $this->navidrome->getScrobblesCount() : 0,
            'unmatched_total' => $this->importTracks->countUnmatched(),
            'missing_mbid' => $navidromeOk ? $this->navidrome->countMediaFilesWithoutMbid() : 0,
            'navidrome_container' => $this->navidromeContainer->getStatus()->value,
            'last_run' => $lastRun === null ? null : [
                'type' => $lastRun->getType(),
                'reference' => $lastRun->getReference(),
                'label' => $lastRun->getLabel(),
                'status' => $lastRun->getStatus(),
                'started_at' => $lastRun->getStartedAt()->format(\DateTimeInterface::ATOM),
                'finished_at' => $lastRun->getFinishedAt()?->format(\DateTimeInterface::ATOM),
                'duration_ms' => $lastRun->getDurationMs(),
            ],
        ]);
    }

    private function extractToken(Request $request): ?string
    {
        $query = $request->query->get('token');
        if (is_string($query) && $query !== '') {
            return $query;
        }
        $header = (string) $request->headers->get('Authorization', '');
        if (str_starts_with($header, 'Bearer ')) {
            $token = substr($header, 7);

            return $token === '' ? null : $token;
        }

        return null;
    }
}
