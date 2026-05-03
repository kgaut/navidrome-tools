<?php

namespace App\Controller;

use App\Entity\RunHistory;
use App\LastFm\LastFmAuthService;
use App\Service\BackupService;
use App\Service\RunHistoryRecorder;
use App\Service\SettingsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

class SettingsController extends AbstractController
{
    public function __construct(
        private readonly BackupService $backupService,
        private readonly RunHistoryRecorder $recorder,
        private readonly string $projectDir,
        private readonly int $dataBackupRetentionDays,
    ) {
    }

    #[Route('/settings', name: 'app_settings', methods: ['GET', 'POST'])]
    public function index(Request $request, SettingsService $settings, LastFmAuthService $lastfmAuth): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('settings', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }
            $limit = max(1, min(1000, (int) $request->request->get('default_limit', 50)));
            $template = trim((string) $request->request->get('default_template', ''));
            if ($template === '') {
                $template = SettingsService::DEFAULT_NAME_TEMPLATE;
            }
            $settings->setDefaultLimit($limit);
            $settings->setDefaultNameTemplate($template);
            $this->addFlash('success', 'Réglages enregistrés.');
            return $this->redirectToRoute('app_settings');
        }

        $backupDir = $this->projectDir . '/var/backups';

        return $this->render('settings/index.html.twig', [
            'default_limit' => $settings->getDefaultLimit(),
            'default_template' => $settings->getDefaultNameTemplate(),
            'lastfm_auth_configured' => $lastfmAuth->isConfigured(),
            'lastfm_session_user' => $lastfmAuth->getStoredSessionUser(),
            'data_backups' => $this->backupService->listBackups($backupDir, 'data'),
            'navidrome_backups' => $this->backupService->listBackups($backupDir, 'navidrome'),
        ]);
    }

    #[Route('/settings/backups/data/run', name: 'app_settings_backup_run_data', methods: ['POST'])]
    public function runDataBackup(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('backup-run-data', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $sourcePath = $this->projectDir . '/var/data.db';
        $backupDir = $this->projectDir . '/var/backups';
        $retention = $this->dataBackupRetentionDays;

        try {
            $result = $this->recorder->record(
                type: RunHistory::TYPE_DB_BACKUP,
                reference: 'data.db',
                label: 'Backup DB locale (manuel)',
                action: function () use ($sourcePath, $backupDir, $retention): array {
                    $snapshot = $this->backupService->backupSqlite($sourcePath, $backupDir, 'data');
                    $pruned = $this->backupService->pruneOlderThan($backupDir, 'data', $retention);
                    return $snapshot + ['pruned' => $pruned];
                },
                extractMetrics: static fn (array $r): array => [
                    'size_bytes' => $r['size'],
                    'pruned' => $r['pruned'],
                    'path' => $r['path'],
                ],
            );
            $this->addFlash('success', sprintf(
                'Sauvegarde créée : %s (%s).',
                basename($result['path']),
                $this->formatBytes($result['size']),
            ));
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Erreur de sauvegarde : ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_settings');
    }

    #[Route('/settings/backups/{type}/{name}/download', name: 'app_settings_backup_download', methods: ['GET'], requirements: ['type' => 'data|navidrome', 'name' => '[A-Za-z0-9._-]+'])]
    public function downloadBackup(string $type, string $name): Response
    {
        $path = $this->resolveBackupPath($type, $name);
        if ($path === null) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $name,
        );
        $response->headers->set('Content-Type', 'application/gzip');

        return $response;
    }

    #[Route('/settings/backups/{type}/{name}/delete', name: 'app_settings_backup_delete', methods: ['POST'], requirements: ['type' => 'data|navidrome', 'name' => '[A-Za-z0-9._-]+'])]
    public function deleteBackup(string $type, string $name, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('backup-delete' . $name, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $path = $this->resolveBackupPath($type, $name);
        if ($path !== null && @unlink($path)) {
            $this->addFlash('success', sprintf('Sauvegarde supprimée : %s', $name));
        } else {
            $this->addFlash('error', sprintf('Impossible de supprimer la sauvegarde : %s', $name));
        }

        return $this->redirectToRoute('app_settings');
    }

    /**
     * Whitelisted file resolver — refuses any name that doesn't sit
     * directly in `var/backups/` and doesn't match the prefix-based
     * pattern emitted by {@see BackupService::backupSqlite()}. Stops
     * a malicious actor from triggering arbitrary file reads via
     * `name=../../etc/passwd` even though the route requirement
     * already filters out slashes.
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' o';
        }
        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1, ',', ' ') . ' Kio';
        }

        return number_format($bytes / 1024 / 1024, 1, ',', ' ') . ' Mio';
    }

    private function resolveBackupPath(string $type, string $name): ?string
    {
        $backupDir = $this->projectDir . '/var/backups';
        foreach ($this->backupService->listBackups($backupDir, $type) as $entry) {
            if ($entry['name'] === $name) {
                return $entry['path'];
            }
        }

        return null;
    }
}
