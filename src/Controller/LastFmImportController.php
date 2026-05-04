<?php

namespace App\Controller;

use App\Docker\NavidromeContainerException;
use App\Docker\NavidromeContainerManager;
use App\Entity\RunHistory;
use App\Form\LastFmImportType;
use App\LastFm\FetchReport;
use App\LastFm\LastFmBufferProcessor;
use App\LastFm\LastFmFetcher;
use App\LastFm\ProcessReport;
use App\Lidarr\LidarrConfig;
use App\Repository\LastFmBufferedScrobbleRepository;
use App\Repository\LastFmImportTrackRepository;
use App\Service\RunHistoryRecorder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LastFmImportController extends AbstractController
{
    public function __construct(
        private readonly LastFmFetcher $fetcher,
        private readonly LastFmBufferProcessor $processor,
        private readonly LidarrConfig $lidarrConfig,
        private readonly RunHistoryRecorder $recorder,
        private readonly LastFmImportTrackRepository $trackRepo,
        private readonly LastFmBufferedScrobbleRepository $bufferRepo,
        private readonly NavidromeContainerManager $containerManager,
        private readonly string $navidromeUrl,
    ) {
    }

    #[Route('/lastfm/import', name: 'app_lastfm_import', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $defaultUser = (string) ($_ENV['LASTFM_USER'] ?? getenv('LASTFM_USER') ?: '');
        $form = $this->createForm(LastFmImportType::class, $defaultUser !== '' ? ['lastfm_user' => $defaultUser] : null);
        $form->handleRequest($request);

        $report = null;
        $error = null;

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array<string, mixed> $data */
            $data = $form->getData();
            $apiKey = (string) ($data['api_key'] ?? '');
            if ($apiKey === '') {
                $apiKey = (string) ($_ENV['LASTFM_API_KEY'] ?? getenv('LASTFM_API_KEY') ?: '');
            }

            if ($apiKey === '') {
                $error = 'Aucune API key fournie (champ vide et LASTFM_API_KEY non défini).';
            }

            $user = (string) ($data['lastfm_user'] ?? '');
            $isDry = (bool) ($data['dry_run'] ?? false);

            if ($error === null) {
                set_time_limit(0);
                ignore_user_abort(true);
                $dateMin = $data['date_min'] instanceof \DateTimeInterface
                    ? \DateTimeImmutable::createFromInterface($data['date_min'])
                    : null;
                $dateMax = $data['date_max'] instanceof \DateTimeInterface
                    ? \DateTimeImmutable::createFromInterface($data['date_max'])
                    : null;
                try {
                    $report = $this->recorder->record(
                        type: RunHistory::TYPE_LASTFM_FETCH,
                        reference: $user,
                        label: 'Last.fm fetch — ' . $user . ($isDry ? ' [dry-run]' : ''),
                        action: fn (RunHistory $entry) => $this->fetcher->fetch(
                            apiKey: $apiKey,
                            lastFmUser: $user,
                            dateMin: $dateMin,
                            dateMax: $dateMax,
                            maxScrobbles: $data['max_scrobbles'] !== null ? max(1, (int) $data['max_scrobbles']) : null,
                            dryRun: $isDry,
                        ),
                        extractMetrics: static fn (FetchReport $r) => [
                            'fetched' => $r->fetched,
                            'buffered' => $r->buffered,
                            'already_buffered' => $r->alreadyBuffered,
                            'dry_run' => $isDry,
                            'date_min' => $dateMin?->format('Y-m-d'),
                            'date_max' => $dateMax?->format('Y-m-d'),
                        ],
                    );
                } catch (\Throwable $e) {
                    $error = $e->getMessage();
                }
            }
        }

        $containerStatus = $this->containerManager->getStatus();

        return $this->render('lastfm/import.html.twig', [
            'form' => $form->createView(),
            'report' => $report,
            'error' => $error,
            'unmatched_cumulative' => $this->trackRepo->countUnmatched(),
            'buffer_count' => $this->bufferRepo->countAll(),
            'lidarr_configured' => $this->lidarrConfig->isConfigured(),
            'navidrome_url' => rtrim($this->navidromeUrl, '/'),
            'container_configured' => $this->containerManager->isConfigured(),
            'container_status' => $containerStatus->value,
        ]);
    }

    #[Route('/lastfm/process', name: 'app_lastfm_process', methods: ['POST'])]
    public function process(Request $request): Response
    {
        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('lastfm_process', $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $this->containerManager->assertSafeToWrite();
        } catch (NavidromeContainerException $e) {
            $this->addFlash('error', $e->getMessage());

            return new RedirectResponse($this->generateUrl('app_lastfm_import'));
        }

        set_time_limit(0);
        ignore_user_abort(true);

        try {
            $entry = $this->recorder->record(
                type: RunHistory::TYPE_LASTFM_PROCESS,
                reference: 'buffer',
                label: 'Last.fm process buffer',
                action: fn (RunHistory $run) => [$run, $this->processor->process(auditRun: $run)],
                extractMetrics: static fn (array $r) => [
                    'considered' => $r[1]->considered,
                    'inserted' => $r[1]->inserted,
                    'duplicates' => $r[1]->duplicates,
                    'unmatched' => $r[1]->unmatched,
                    'skipped' => $r[1]->skipped,
                    'cache_hits_positive' => $r[1]->cacheHitsPositive,
                    'cache_hits_negative' => $r[1]->cacheHitsNegative,
                    'cache_misses' => $r[1]->cacheMisses,
                ],
            );
            [$runEntry, $report] = $entry;
            /** @var RunHistory $runEntry */
            /** @var ProcessReport $report */

            $this->addFlash('success', sprintf(
                'Buffer traité : %d considérés, %d insérés, %d doublons, %d non matchés, %d ignorés.',
                $report->considered,
                $report->inserted,
                $report->duplicates,
                $report->unmatched,
                $report->skipped,
            ));

            return new RedirectResponse($this->generateUrl('app_history_detail', ['id' => $runEntry->getId()]));
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Échec du traitement du buffer : ' . $e->getMessage());

            return new RedirectResponse($this->generateUrl('app_lastfm_import'));
        }
    }
}
