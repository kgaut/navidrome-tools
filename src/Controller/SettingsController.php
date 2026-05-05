<?php

namespace App\Controller;

use App\LastFm\LastFmAuthService;
use App\Service\SettingsService;
use App\Service\ToolsDatabaseWiper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SettingsController extends AbstractController
{
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

        return $this->render('settings/index.html.twig', [
            'default_limit' => $settings->getDefaultLimit(),
            'default_template' => $settings->getDefaultNameTemplate(),
            'lastfm_auth_configured' => $lastfmAuth->isConfigured(),
            'lastfm_session_user' => $lastfmAuth->getStoredSessionUser(),
            'wipeable_tables' => ToolsDatabaseWiper::wipedTables(),
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
}
