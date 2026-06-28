<?php

namespace App\Controller;

use App\Lidarr\LidarrClient;
use App\Lidarr\LidarrException;
use App\Message\ComputeRecommendationsMessage;
use App\Recommendation\ListenBrainzClient;
use App\Recommendation\RecommendationStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Review page for the artist recommendations snapshot (computed by the
 * engine / CLI / async job). Lets me add a recommended artist to Lidarr in
 * one click, or dismiss it so it never resurfaces. Adding is always a manual,
 * per-artist action — never automatic — because an add triggers downloads.
 */
class RecommendationController extends AbstractController
{
    #[Route('/recommendations', name: 'app_recommendations_index', methods: ['GET'])]
    public function index(
        RecommendationStore $store,
        LidarrClient $lidarr,
        ListenBrainzClient $listenBrainz,
    ): Response {
        $snapshot = $store->load();

        return $this->render('recommendations/index.html.twig', [
            'generatedAt' => $snapshot['generated_at'] ?? null,
            'recommendations' => $snapshot['items'] ?? [],
            'lidarrConfigured' => $lidarr->isConfigured(),
            'listenBrainzConfigured' => $listenBrainz->isConfigured(),
        ]);
    }

    #[Route('/recommendations/refresh', name: 'app_recommendations_refresh', methods: ['POST'])]
    public function refresh(Request $request, MessageBusInterface $bus): Response
    {
        if (!$this->isCsrfTokenValid('recommendations_refresh', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $bus->dispatch(new ComputeRecommendationsMessage());
        $this->addFlash('success', 'Calcul des recommandations lancé en arrière-plan.');

        return $this->redirectToRoute('app_history');
    }

    #[Route('/recommendations/add', name: 'app_recommendations_add', methods: ['POST'])]
    public function add(Request $request, LidarrClient $lidarr, RecommendationStore $store): Response
    {
        if (!$this->isCsrfTokenValid('recommendations_add', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $mbid = trim((string) $request->request->get('mbid'));
        $name = trim((string) $request->request->get('name'));
        if ($mbid === '') {
            $this->addFlash('error', 'MBID manquant — impossible d\'ajouter cet artiste à Lidarr.');

            return $this->redirectToRoute('app_recommendations_index');
        }

        try {
            $lidarr->addArtist($mbid);
            $store->removeFromSnapshot($mbid, $name);
            $this->addFlash('success', sprintf('« %s » ajouté à Lidarr.', $name !== '' ? $name : $mbid));
        } catch (LidarrException $e) {
            $this->addFlash('error', sprintf('Échec de l\'ajout à Lidarr : %s', $e->getMessage()));
        }

        return $this->redirectToRoute('app_recommendations_index');
    }

    #[Route('/recommendations/ignore', name: 'app_recommendations_ignore', methods: ['POST'])]
    public function ignore(Request $request, RecommendationStore $store): Response
    {
        if (!$this->isCsrfTokenValid('recommendations_ignore', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $mbid = trim((string) $request->request->get('mbid'));
        $name = trim((string) $request->request->get('name'));
        if ($name === '' && $mbid === '') {
            throw $this->createNotFoundException('Recommandation inconnue.');
        }

        $store->ignore($mbid !== '' ? $mbid : null, $name);
        $store->removeFromSnapshot($mbid !== '' ? $mbid : null, $name);
        $this->addFlash('success', sprintf('« %s » ignoré — il ne réapparaîtra plus.', $name !== '' ? $name : $mbid));

        return $this->redirectToRoute('app_recommendations_index');
    }
}
