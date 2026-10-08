<?php

namespace App\Controller;

use App\AudioMuse\AudioMuseClient;
use App\Entity\DescribedPlaylist;
use App\Message\GeneratePlaylistsMessage;
use App\Playlist\Definition\DescribedPlaylistDefinition;
use App\Playlist\PlaylistEnablement;
use App\Playlist\PlaylistGenerator;
use App\Repository\DescribedPlaylistRepository;
use App\Subsonic\SubsonicClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lecture seule des playlists Navidrome via l'API Subsonic.
 *
 * Premier slice du portage de la feature playlist depuis le POC : on
 * pose le SubsonicClient et les deux pages de lecture (liste + détail).
 * Les actions d'écriture (créer, renommer, supprimer, star, etc.)
 * arriveront dans des PRs séparées une fois la fondation validée.
 *
 * La DB Navidrome reste montée `:ro` : aucun accès direct côté tool —
 * toutes les requêtes passent par `/rest/*.view` de Navidrome.
 */
class PlaylistController extends AbstractController
{
    #[Route('/playlists', name: 'app_playlists_index', methods: ['GET'])]
    public function index(
        SubsonicClient $subsonic,
        PlaylistGenerator $generator,
        DescribedPlaylistRepository $described,
        AudioMuseClient $audioMuse,
    ): Response {
        try {
            $playlists = $subsonic->getPlaylists();
            $error = null;
        } catch (\Throwable $e) {
            $playlists = [];
            $error = $e->getMessage();
        }

        // Tri par date de modification descendante. Subsonic les renvoie
        // dans un ordre arbitraire — on normalise côté tool pour que les
        // playlists récemment touchées remontent en haut.
        usort($playlists, static fn (array $a, array $b): int => strcmp(
            (string) ($b['changed'] ?? ''),
            (string) ($a['changed'] ?? ''),
        ));

        return $this->render('playlist_management/index.html.twig', [
            'playlists' => $playlists,
            'error' => $error,
            'definitions' => $generator->listDefinitions(),
            'described' => $described->findAllOrdered(),
            'described_prefix' => DescribedPlaylistDefinition::SLUG_PREFIX,
            'audiomuse_configured' => $audioMuse->isConfigured(),
        ]);
    }

    /**
     * Save a playlist described in free text (issue #258), then generate it
     * right away. It is regenerated afterwards like any other definition.
     */
    #[Route('/playlists/described', name: 'app_playlists_described_create', methods: ['POST'])]
    public function createDescribed(
        Request $request,
        PlaylistGenerator $generator,
        EntityManagerInterface $em,
        MessageBusInterface $bus,
    ): Response {
        if (!$this->isCsrfTokenValid('playlists_described', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $name = trim((string) $request->request->get('name'));
        $query = trim((string) $request->request->get('query'));
        $count = $request->request->getInt('track_count', 30);
        $maxPlaysRaw = trim((string) $request->request->get('max_plays'));
        $maxPlays = $maxPlaysRaw === '' ? null : (int) $maxPlaysRaw;

        $taken = array_map(
            static fn (array $d): string => mb_strtolower($d['name']),
            $generator->listDefinitions(),
        );
        $error = match (true) {
            $name === '' || mb_strlen($name) > 100 => 'Le nom est obligatoire (100 caractères au plus).',
            in_array(mb_strtolower($name), $taken, true) => sprintf('Une playlist générée s\'appelle déjà « %s ».', $name),
            $query === '' || mb_strlen($query) > 300 => 'La description est obligatoire (300 caractères au plus).',
            $count < 1 || $count > 200 => 'Le nombre de morceaux doit être compris entre 1 et 200.',
            $maxPlays !== null && $maxPlays < 0 => 'Le plafond d\'écoutes ne peut pas être négatif.',
            default => null,
        };
        if ($error !== null) {
            $this->addFlash('error', $error);

            return $this->redirectToRoute('app_playlists_index');
        }

        $playlist = new DescribedPlaylist($name, $query, $count, $maxPlays);
        $em->persist($playlist);
        $em->flush();

        $bus->dispatch(new GeneratePlaylistsMessage(DescribedPlaylistDefinition::SLUG_PREFIX . $playlist->getId()));
        $this->addFlash('success', sprintf('Playlist « %s » enregistrée, génération lancée en arrière-plan.', $name));

        return $this->redirectToRoute('app_history');
    }

    /**
     * Forget a described playlist: it is no longer regenerated. The playlist
     * already written to Navidrome is left untouched.
     */
    #[Route('/playlists/described/{id}/delete', name: 'app_playlists_described_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteDescribed(
        int $id,
        Request $request,
        DescribedPlaylistRepository $described,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('playlists_described_delete', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $playlist = $described->find($id) ?? throw $this->createNotFoundException();

        $em->remove($playlist);
        $em->flush();
        $this->addFlash('success', sprintf(
            '« %s » ne sera plus régénérée. La playlist existante dans Navidrome n\'est pas supprimée.',
            $playlist->getName(),
        ));

        return $this->redirectToRoute('app_playlists_index');
    }

    #[Route('/playlists/generate', name: 'app_playlists_generate', methods: ['POST'])]
    public function generateAll(Request $request, MessageBusInterface $bus): Response
    {
        if (!$this->isCsrfTokenValid('playlists_generate', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $bus->dispatch(new GeneratePlaylistsMessage(null));
        $this->addFlash('success', 'Génération de toutes les playlists activées lancée en arrière-plan.');

        return $this->redirectToRoute('app_history');
    }

    #[Route('/playlists/generate/{slug}', name: 'app_playlists_generate_one', methods: ['POST'])]
    public function generateOne(string $slug, Request $request, MessageBusInterface $bus): Response
    {
        if (!$this->isCsrfTokenValid('playlists_generate_one', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $bus->dispatch(new GeneratePlaylistsMessage($slug));
        $this->addFlash('success', sprintf('Génération de la playlist « %s » lancée en arrière-plan.', $slug));

        return $this->redirectToRoute('app_history');
    }

    #[Route('/playlists/{slug}/toggle', name: 'app_playlists_toggle', methods: ['POST'])]
    public function toggle(
        string $slug,
        Request $request,
        PlaylistGenerator $generator,
        PlaylistEnablement $enablement,
    ): Response {
        if (!$this->isCsrfTokenValid('playlists_toggle', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $known = array_column($generator->listDefinitions(), 'slug');
        if (!in_array($slug, $known, true)) {
            throw $this->createNotFoundException(sprintf('Unknown playlist "%s".', $slug));
        }

        $enablement->setEnabled($slug, $request->request->getBoolean('enabled'));

        // 204: the checkbox toggles via fetch() and stays put — no reload.
        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/playlists/{id}', name: 'app_playlists_show', methods: ['GET'])]
    public function show(string $id, SubsonicClient $subsonic): Response
    {
        try {
            $playlist = $subsonic->getPlaylist($id);
        } catch (\Throwable $e) {
            // Subsonic répond `error` aussi bien sur 404 que sur auth fail
            // — on traduit en 404 côté UI plutôt que de surfacer une 500.
            throw $this->createNotFoundException(sprintf(
                'Playlist %s introuvable côté Navidrome : %s',
                $id,
                $e->getMessage(),
            ));
        }

        return $this->render('playlist_management/show.html.twig', [
            'playlist' => $playlist,
        ]);
    }
}
