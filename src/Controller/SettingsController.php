<?php

namespace App\Controller;

use App\Entity\RunHistory;
use App\LastFm\LastFmAuthService;
use App\Notifier\Notification;
use App\Notifier\Notifier;
use App\Service\SettingsService;
use App\Service\ToolsDatabaseWiper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SettingsController extends AbstractController
{
    #[Route('/settings', name: 'app_settings', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        SettingsService $settings,
        LastFmAuthService $lastfmAuth,
        Notifier $notifier,
    ): Response {
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

        return $this->render('settings/index.html.twig', [
            'default_limit' => $settings->getDefaultLimit(),
            'default_template' => $settings->getDefaultNameTemplate(),
            'lastfm_auth_configured' => $lastfmAuth->isConfigured(),
            'lastfm_session_user' => $lastfmAuth->getStoredSessionUser(),
            'wipeable_tables' => ToolsDatabaseWiper::wipedTables(),
            'notifier_enabled' => $notifier->isEnabled(),
            'notifier_drivers' => $notifier->describeDrivers(),
            'notifier_on' => $notifier->getNotifyOn(),
        ]);
    }

    #[Route('/settings/wipe-tools-database', name: 'app_settings_wipe_database', methods: ['POST'])]
    public function wipeToolsDatabase(Request $request, ToolsDatabaseWiper $wiper): Response
    {
        if (!$this->isCsrfTokenValid('settings_wipe_database', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if (trim((string) $request->request->get('confirm', '')) !== 'WIPE') {
            $this->addFlash('warning', 'Confirmation manquante : tapez WIPE pour vider la base.');
            return $this->redirectToRoute('app_settings');
        }

        $deleted = $wiper->wipe();
        $total = array_sum($deleted);
        $this->addFlash(
            'success',
            sprintf('Base tools vidée : %d lignes supprimées (alias et réglages conservés).', $total),
        );
        return $this->redirectToRoute('app_settings');
    }

    #[Route('/settings/test-notification', name: 'app_settings_test_notification', methods: ['POST'])]
    public function testNotification(Request $request, Notifier $notifier): Response
    {
        if (!$this->isCsrfTokenValid('settings_test_notification', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if (!$notifier->isEnabled()) {
            $this->addFlash('warning', 'Aucun driver actif dans `NOTIFY_DRIVERS` — impossible d\'envoyer un test.');
            return $this->redirectToRoute('app_settings');
        }

        $kind = $request->request->get('kind') === 'error' ? 'error' : 'success';
        $notification = $kind === 'error'
            ? new Notification(
                type: 'test',
                label: 'Notification de test (erreur simulée)',
                status: RunHistory::STATUS_ERROR,
                durationMs: 123,
                errorMessage: 'Ceci est un message d\'erreur factice envoyé depuis /settings.',
            )
            : new Notification(
                type: 'test',
                label: 'Notification de test',
                status: RunHistory::STATUS_SUCCESS,
                durationMs: 456,
                metrics: ['fired_from' => 'settings', 'kind' => 'manual-test'],
            );

        $results = $notifier->testSend($notification);

        $sent = [];
        $skipped = [];
        $failed = [];
        foreach ($results as $name => $outcome) {
            if ($outcome === 'sent') {
                $sent[] = $name;
            } elseif (str_starts_with($outcome, 'error:')) {
                $failed[] = sprintf('%s (%s)', $name, substr($outcome, 6));
            } else {
                $skipped[] = sprintf('%s (%s)', $name, substr($outcome, 8));
            }
        }

        if ($sent !== []) {
            $this->addFlash(
                'success',
                sprintf('Test %s envoyé via : %s.', $kind, implode(', ', $sent)),
            );
        }
        if ($failed !== []) {
            $this->addFlash(
                'error',
                sprintf('Échec sur : %s.', implode(', ', $failed)),
            );
        }
        if ($skipped !== []) {
            $this->addFlash(
                'warning',
                sprintf('Skippé : %s.', implode(', ', $skipped)),
            );
        }
        if ($sent === [] && $failed === [] && $skipped === []) {
            $this->addFlash('warning', 'Aucun driver à dispatcher (la liste NOTIFY_DRIVERS ne mentionne aucun driver enregistré).');
        }

        return $this->redirectToRoute('app_settings');
    }
}
