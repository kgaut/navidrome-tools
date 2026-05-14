<?php

namespace App\Controller;

use App\Entity\RunHistory;
use App\Repository\LastFmBufferedScrobbleRepository;
use App\Service\RunHistoryRecorder;
use App\Strawberry\StrawberryBufferProcessor;
use App\Strawberry\StrawberryProcessReport;
use App\Strawberry\StrawberryRepository;
use App\Strawberry\StrawberryUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

class StrawberryController extends AbstractController
{
    public function __construct(
        private readonly StrawberryUploadService $uploadService,
        private readonly LastFmBufferedScrobbleRepository $bufferRepo,
        private readonly EntityManagerInterface $em,
        private readonly RunHistoryRecorder $recorder,
    ) {
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

            return $this->redirectToRoute('app_lastfm_import');
        }

        try {
            $this->uploadService->save($file);
            $info = $this->uploadService->getUploadInfo();
            $size = $info !== null ? $this->formatBytes($info['size']) : '?';
            $this->addFlash('success', sprintf('Base Strawberry uploadée (%s). Lancez le process puis téléchargez.', $size));
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', 'Upload invalide : ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_lastfm_import');
    }

    #[Route('/strawberry/process', name: 'app_strawberry_process', methods: ['POST'])]
    public function process(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('strawberry_process', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->uploadService->hasUpload()) {
            $this->addFlash('error', 'Aucun fichier Strawberry uploadé à traiter.');

            return $this->redirectToRoute('app_lastfm_import');
        }

        set_time_limit(0);

        $repo = new StrawberryRepository($this->uploadService->getUploadPath());
        $processor = new StrawberryBufferProcessor($this->bufferRepo, $repo, $this->em);

        try {
            $report = $this->recorder->record(
                type: RunHistory::TYPE_STRAWBERRY_PROCESS,
                reference: 'upload',
                label: 'Strawberry process (uploaded DB)',
                action: fn (RunHistory $entry) => $processor->process(auditRun: $entry),
                extractMetrics: static fn (StrawberryProcessReport $r) => [
                    'considered' => $r->considered,
                    'matched' => $r->matched,
                    'unmatched' => $r->unmatched,
                ],
            );

            $this->addFlash('success', sprintf(
                'Strawberry sync terminé : %d scrobbles traités, %d matchés, %d non matchés.',
                $report->considered,
                $report->matched,
                $report->unmatched,
            ));
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Erreur lors du traitement Strawberry : ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_lastfm_import');
    }

    #[Route('/strawberry/download', name: 'app_strawberry_download', methods: ['GET'])]
    public function download(): Response
    {
        if (!$this->uploadService->hasUpload()) {
            $this->addFlash('error', 'Aucun fichier Strawberry disponible au téléchargement.');

            return $this->redirectToRoute('app_lastfm_import');
        }

        $response = new BinaryFileResponse($this->uploadService->getUploadPath());
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            'strawberry.db',
        );

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

        return $this->redirectToRoute('app_lastfm_import');
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1) . ' Mo';
        }

        return round($bytes / 1024, 0) . ' Ko';
    }
}
