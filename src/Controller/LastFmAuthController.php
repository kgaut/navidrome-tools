<?php

namespace App\Controller;

use App\LastFm\LastFmAuthService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LastFmAuthController extends AbstractController
{
    public function __construct(
        private readonly LastFmAuthService $authService,
    ) {
    }

    #[Route('/lastfm/connect', name: 'app_lastfm_connect', methods: ['GET'])]
    public function connect(): Response
    {
        if (!$this->authService->isConfigured()) {
            $this->addFlash('error', 'LASTFM_API_KEY et LASTFM_API_SECRET doivent être renseignés pour pouvoir se connecter à Last.fm.');

            return $this->redirectToRoute('app_settings');
        }

        $callbackUrl = $this->generateUrl(
            'app_lastfm_connect_callback',
            [],
            \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return $this->redirect($this->authService->buildAuthorizeUrl($callbackUrl));
    }

    #[Route('/lastfm/connect/callback', name: 'app_lastfm_connect_callback', methods: ['GET'])]
    public function callback(Request $request): Response
    {
        $token = (string) $request->query->get('token', '');
        if ($token === '') {
            $this->addFlash('error', 'Last.fm n\'a pas renvoyé de token. Recommencez la connexion.');

            return $this->redirectToRoute('app_settings');
        }

        try {
            $this->authService->exchangeTokenForSession($token);
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Échec de l\'échange token → session Last.fm : ' . $e->getMessage());

            return $this->redirectToRoute('app_settings');
        }

        $user = $this->authService->getStoredSessionUser() ?? '';
        $this->addFlash('success', sprintf('Connecté à Last.fm en tant que « %s ».', $user));

        return $this->redirectToRoute('app_settings');
    }

    #[Route('/lastfm/connect/disconnect', name: 'app_lastfm_connect_disconnect', methods: ['POST'])]
    public function disconnect(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('lastfm-disconnect', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $this->authService->clearStoredSession();
        $this->addFlash('success', 'Session Last.fm révoquée localement.');

        return $this->redirectToRoute('app_settings');
    }
}
