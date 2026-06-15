<?php

namespace App\Controller;

use App\Subsonic\SubsonicClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
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
    public function index(SubsonicClient $subsonic): Response
    {
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
        ]);
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
