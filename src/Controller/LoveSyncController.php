<?php

namespace App\Controller;

use App\LastFm\LastFmAuthService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LoveSyncController extends AbstractController
{
    public function __construct(
        private readonly LastFmAuthService $authService,
    ) {
    }

    #[Route('/lastfm/love-sync', name: 'app_lastfm_love_sync', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('lastfm/love_sync.html.twig', [
            'auth_configured' => $this->authService->isConfigured(),
            'session_user' => $this->authService->getStoredSessionUser(),
        ]);
    }
}
