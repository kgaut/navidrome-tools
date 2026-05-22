<?php

namespace App\Controller;

use App\Entity\RunHistory;
use App\Entity\ScrobbleSync;
use App\Message\RematchMessage;
use App\Message\SyncStrawberryMessage;
use App\Repository\ScrobbleSyncRepository;
use App\Service\RunHistoryRecorder;
use App\Strawberry\StrawberrySyncReport;
use App\Strawberry\StrawberrySyncService;
use App\Strawberry\StrawberryRepository;
use App\Strawberry\StrawberryUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

class StrawberryController extends AbstractController
{
    public function __construct(
        private readonly StrawberryUploadService $uploadService,
        private readonly ScrobbleSyncRepository $syncRepo,
        private readonly EntityManagerInterface $em,
        private readonly RunHistoryRecorder $recorder,
        private readonly StrawberryRepository $strawberry,
    ) {
    }

    #[Route('/strawberry/sync', name: 'app_strawberry_sync', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('strawberry/sync.html.twig', [
            'pending' => $this->syncRepo->countPendingForTarget(ScrobbleSync::TARGET_STRAWBERRY),
            'matched' => $this->syncRepo->countByTargetStatus(ScrobbleSync::TARGET_STRAWBERRY, ScrobbleSync::STATUS_MATCHED),
            'unmatched' => $this->syncRepo->countByTargetStatus(ScrobbleSync::TARGET_STRAWBERRY, ScrobbleSync::STATUS_UNMATCHED),
            'strawberry_configured' => $this->strawberry->isAvailable(),
            'upload_info' => $this->uploadService->getUploadInfo(),
        ]);
    }

    #[Route('/strawberry/sync', name: 'app_strawberry_sync_post', methods: ['POST'])]
    public function sync(Request $request, MessageBusInterface $bus): Response
    {
        if (!$this->isCsrfTokenValid('strawberry_sync', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $dryRun = (bool) $request->request->get('dry_run');
        $retryUnmatched = (bool) $request->request->get('retry_unmatched');
        $limit = max(0, (int) $request->request->get('limit', 0));

        $bus->dispatch(new SyncStrawberryMessage(
            limit: $limit,
            dryRun: $dryRun,
            retryUnmatched: $retryUnmatched,
        ));

        $this->addFlash('success', 'Sync Strawberry lancé en arrière-plan.');
        return $this->redirectToRoute('app_history');
    }

    #[Route('/strawberry/upload', name: 'app_strawberry_upload', methods: ['POST'])]
    public function upload(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('strawberry_upload', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $file = $request->files->get('db_file');
        if ($file === null) {
            $this->addFlash('error', 'Aucun fichier reçu.');
            return $this->redirectToRoute('app_strawberry_sync');
        }

        try {
            $this->uploadService->save($file);
            $info = $this->uploadService->getUploadInfo();
            $size = $info !== null ? $this->formatBytes($info['size']) : '?';
            $this->addFlash('success', sprintf('Base Strawberry uploadée (%s).', $size));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', 'Upload invalide : ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_strawberry_sync');
    }

    #[Route('/strawberry/process-upload', name: 'app_strawberry_process_upload', methods: ['POST'])]
    public function processUpload(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('strawberry_process', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->uploadService->hasUpload()) {
            $this->addFlash('error', 'Aucun fichier Strawberry uploadé.');
            return $this->redirectToRoute('app_strawberry_sync');
        }

        set_time_limit(0);
        $retryUnmatched = (bool) $request->request->get('retry_unmatched');
        $repo = new StrawberryRepository($this->uploadService->getUploadPath());
        $processor = new StrawberrySyncService($this->syncRepo, $repo, $this->em);

        try {
            $report = $this->recorder->record(
                type: RunHistory::TYPE_STRAWBERRY_SYNC,
                reference: 'upload',
                label: 'Strawberry sync (uploaded DB)' . ($retryUnmatched ? ' +retry' : ''),
                action: fn (RunHistory $entry) => $processor->process(
                    retryUnmatched: $retryUnmatched,
                    run: $entry,
                ),
                extractMetrics: static fn (StrawberrySyncReport $r) => [
                    'considered' => $r->considered,
                    'matched' => $r->matched,
                    'unmatched' => $r->unmatched,
                ],
            );

            $this->addFlash('success', sprintf(
                'Sync Strawberry terminé : %d matchés, %d non matchés.',
                $report->matched,
                $report->unmatched,
            ));
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Erreur : ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_strawberry_sync');
    }

    #[Route('/strawberry/download', name: 'app_strawberry_download', methods: ['GET'])]
    public function download(): Response
    {
        if (!$this->uploadService->hasUpload()) {
            $this->addFlash('error', 'Aucun fichier disponible.');
            return $this->redirectToRoute('app_strawberry_sync');
        }

        $response = new BinaryFileResponse($this->uploadService->getUploadPath());
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'strawberry.db');
        return $response;
    }

    #[Route('/strawberry/delete-upload', name: 'app_strawberry_delete_upload', methods: ['POST'])]
    public function deleteUpload(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('strawberry_delete_upload', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $this->uploadService->delete();
        $this->addFlash('success', 'Fichier Strawberry supprimé.');
        return $this->redirectToRoute('app_strawberry_sync');
    }

    #[Route('/strawberry/rematch', name: 'app_strawberry_rematch', methods: ['POST'])]
    public function rematch(Request $request, MessageBusInterface $bus): Response
    {
        if (!$this->isCsrfTokenValid('strawberry_rematch', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $bus->dispatch(new RematchMessage(
            target: ScrobbleSync::TARGET_STRAWBERRY,
            limit: max(0, (int) $request->request->get('limit', 0)),
            dryRun: (bool) $request->request->get('dry_run'),
            autoStop: false,
        ));

        $this->addFlash('success', 'Rematch Strawberry lancé en arrière-plan.');
        return $this->redirectToRoute('app_history');
    }

    #[Route('/strawberry/sync/reset', name: 'app_strawberry_sync_reset', methods: ['POST'])]
    public function reset(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('strawberry_sync_reset', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $reset = $this->syncRepo->resetAllToPending(ScrobbleSync::TARGET_STRAWBERRY);
        $this->addFlash('success', sprintf('Reset Strawberry : %d ligne(s) remises en pending.', $reset));

        return $this->redirectToRoute('app_strawberry_sync');
    }

    #[Route('/strawberry/unmatched', name: 'app_strawberry_unmatched', methods: ['GET'])]
    public function unmatched(Request $request): Response
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = 50;
        $filterArtist = trim((string) $request->query->get('artist', ''));
        $filterTitle = trim((string) $request->query->get('title', ''));

        $rows = $this->syncRepo->aggregateUnmatched(
            target: ScrobbleSync::TARGET_STRAWBERRY,
            limit: $perPage,
            offset: ($page - 1) * $perPage,
            filterArtist: $filterArtist !== '' ? $filterArtist : null,
            filterTitle: $filterTitle !== '' ? $filterTitle : null,
        );
        $total = $this->syncRepo->countUnmatchedForTarget(ScrobbleSync::TARGET_STRAWBERRY);

        return $this->render('strawberry/unmatched.html.twig', [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'filter_artist' => $filterArtist,
            'filter_title' => $filterTitle,
        ]);
    }

    private function formatBytes(int $bytes): string
    {
        return $bytes >= 1024 * 1024
            ? round($bytes / (1024 * 1024), 1) . ' Mo'
            : round($bytes / 1024, 0) . ' Ko';
    }
}
