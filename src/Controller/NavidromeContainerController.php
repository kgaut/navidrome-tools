<?php

namespace App\Controller;

use App\Docker\NavidromeContainerException;
use App\Docker\NavidromeContainerManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class NavidromeContainerController extends AbstractController
{
    public function __construct(
        private readonly NavidromeContainerManager $manager,
    ) {
    }

    #[Route('/navidrome/container/start', name: 'app_navidrome_container_start', methods: ['POST'])]
    public function start(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('navidrome_container', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->manager->isConfigured()) {
            $this->addFlash('error', 'NAVIDROME_CONTAINER_NAME n\'est pas renseignée.');

            return $this->backTo($request);
        }

        try {
            $this->manager->start();
            $this->addFlash('success', 'Conteneur Navidrome démarré.');
        } catch (NavidromeContainerException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->backTo($request);
    }

    #[Route('/navidrome/container/stop', name: 'app_navidrome_container_stop', methods: ['POST'])]
    public function stop(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('navidrome_container', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->manager->isConfigured()) {
            $this->addFlash('error', 'NAVIDROME_CONTAINER_NAME n\'est pas renseignée.');

            return $this->backTo($request);
        }

        try {
            $this->manager->stop();
            $this->addFlash('success', 'Conteneur Navidrome arrêté.');
        } catch (NavidromeContainerException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->backTo($request);
    }

    private function backTo(Request $request): Response
    {
        $back = (string) $request->request->get('_back', '');
        if ($back !== '' && str_starts_with($back, '/')) {
            return $this->redirect($back);
        }

        return $this->redirectToRoute('app_dashboard');
    }
}
