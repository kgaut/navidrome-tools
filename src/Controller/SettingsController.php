<?php

namespace App\Controller;

use App\LastFm\LastFmAuthService;
use App\Service\SettingsService;
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
        ]);
    }
}
