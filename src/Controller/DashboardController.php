<?php

namespace App\Controller;

use App\Generator\GeneratorRegistry;
use App\Navidrome\NavidromeRepository;
use App\Repository\PlaylistDefinitionRepository;
use App\Repository\RunHistoryRepository;
use App\Service\SettingsService;
use App\Subsonic\SubsonicClient;
use Cron\CronExpression;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    private const ALLOWED_SORTS = ['name', 'last_run', 'schedule'];

    #[Route('/', name: 'app_dashboard')]
    public function index(
        Request $request,
        PlaylistDefinitionRepository $repository,
        GeneratorRegistry $registry,
        NavidromeRepository $navidrome,
        SubsonicClient $subsonic,
        SettingsService $settings,
        RunHistoryRepository $runHistory,
    ): Response {
        $q = trim((string) $request->query->get('q', ''));
        $enabledRaw = $request->query->get('enabled');
        $enabled = in_array($enabledRaw, ['1', '0'], true) ? ($enabledRaw === '1') : null;
        $sortRaw = (string) $request->query->get('sort', 'name');
        $sort = in_array($sortRaw, self::ALLOWED_SORTS, true) ? $sortRaw : 'name';

        $definitions = $repository->findFiltered([
            'q' => $q !== '' ? $q : null,
            'enabled' => $enabled,
            'sort' => $sort,
        ]);

        $rows = [];
        foreach ($definitions as $def) {
            $next = null;
            $schedule = $def->getSchedule();
            if ($schedule) {
                try {
                    $next = (new CronExpression($schedule))->getNextRunDate(new \DateTimeImmutable());
                } catch (\Throwable) {
                    $next = null;
                }
            }
            $rows[] = [
                'def' => $def,
                'generator' => $registry->has($def->getGeneratorKey()) ? $registry->get($def->getGeneratorKey()) : null,
                'next_run' => $next,
            ];
        }

        $hasScrobbles = $navidrome->isAvailable() && $navidrome->hasScrobblesTable();
        $health = [
            'navidrome_db' => $navidrome->isAvailable(),
            'has_scrobbles' => $hasScrobbles,
            'scrobbles_count' => $hasScrobbles ? $navidrome->getScrobblesCount() : null,
            'subsonic' => $subsonic->ping(),
            'missing_mbid_count' => $navidrome->isAvailable() ? $navidrome->countMediaFilesWithoutMbid() : null,
        ];

        $recentRuns = $runHistory->findFilteredPaginated([], 1, 10)['items'];

        return $this->render('dashboard/index.html.twig', [
            'rows' => $rows,
            'health' => $health,
            'recent_runs' => $recentRuns,
            'default_limit' => $settings->getDefaultLimit(),
            'default_template' => $settings->getDefaultNameTemplate(),
            'filters' => [
                'q' => $q,
                'enabled' => $enabled,
                'sort' => $sort,
            ],
        ]);
    }
}
