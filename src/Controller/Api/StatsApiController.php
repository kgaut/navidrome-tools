<?php

namespace App\Controller\Api;

use App\Service\DailyListeningStatsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * JSON API — daily aggregated listening stats (issue #250). Guarded by the
 * stateless `api` firewall (Bearer token). See {@see DailyListeningStatsService}
 * for the cross-source semantics.
 */
class StatsApiController extends AbstractController
{
    /** Max span of a single range request, in days (guards the query). */
    private const MAX_RANGE_DAYS = 366;

    #[Route('/api/stats/daily', name: 'api_stats_daily', methods: ['GET'])]
    public function daily(Request $request, DailyListeningStatsService $service): JsonResponse
    {
        $source = (string) $request->query->get('source', DailyListeningStatsService::SOURCE_NAVIDROME);
        if (!in_array($source, DailyListeningStatsService::SOURCES, true)) {
            return $this->error('Invalid "source"; use "navidrome" or "history" ("lastfm" is a deprecated alias of "history").');
        }

        $tz = new \DateTimeZone($service->timezone());
        $today = new \DateTimeImmutable('today', $tz);

        $toRaw = (string) $request->query->get('to', $today->format('Y-m-d'));
        $fromRaw = (string) $request->query->get('from', $toRaw);

        $from = self::parseDay($fromRaw, $tz);
        $to = self::parseDay($toRaw, $tz);
        if ($from === null || $to === null) {
            return $this->error('Invalid "from"/"to"; expected YYYY-MM-DD.');
        }
        if ($from > $to) {
            return $this->error('"from" must be on or before "to".');
        }
        if ((int) $from->diff($to)->days > self::MAX_RANGE_DAYS - 1) {
            return $this->error(sprintf('Range too large (max %d days).', self::MAX_RANGE_DAYS));
        }

        return $this->series($service, $source, $from, $to);
    }

    #[Route('/api/stats/daily/{day}', name: 'api_stats_daily_one', methods: ['GET'], requirements: ['day' => '\d{4}-\d{2}-\d{2}'])]
    public function dailyOne(string $day, Request $request, DailyListeningStatsService $service): JsonResponse
    {
        $source = (string) $request->query->get('source', DailyListeningStatsService::SOURCE_NAVIDROME);
        if (!in_array($source, DailyListeningStatsService::SOURCES, true)) {
            return $this->error('Invalid "source"; use "navidrome" or "history" ("lastfm" is a deprecated alias of "history").');
        }

        $tz = new \DateTimeZone($service->timezone());
        $d = self::parseDay($day, $tz);
        if ($d === null) {
            return $this->error('Invalid day; expected YYYY-MM-DD.');
        }

        return $this->series($service, $source, $d, $d);
    }

    private function series(
        DailyListeningStatsService $service,
        string $source,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): JsonResponse {
        return new JsonResponse([
            'source' => $source,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'timezone' => $service->timezone(),
            'days' => $service->daily($source, $from, $to),
        ]);
    }

    /** Parse a YYYY-MM-DD day to local midnight, or null if malformed/invalid. */
    private static function parseDay(string $value, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $tz);
        if ($d === false || $d->format('Y-m-d') !== $value) {
            return null; // rejects e.g. 2026-02-31
        }

        return $d;
    }

    private function error(string $message): JsonResponse
    {
        return new JsonResponse(['error' => $message], JsonResponse::HTTP_BAD_REQUEST);
    }
}
