<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HelpController extends AbstractController
{
    private readonly string $composeService;

    public function __construct(?string $composeService = null)
    {
        // Defaultable env (`default::HELP_COMPOSE_SERVICE`) resolves to null
        // when the variable isn't set at all — fall back to the conventional
        // service name from docker-compose.dev.yml so a fresh checkout still
        // renders useful snippets without extra config.
        $composeService = is_string($composeService) ? trim($composeService) : '';
        $this->composeService = $composeService !== '' ? $composeService : 'navidrome-tools-web';
    }

    #[Route('/help', name: 'app_help', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('help.html.twig', [
            'compose_service' => $this->composeService,
        ]);
    }
}
