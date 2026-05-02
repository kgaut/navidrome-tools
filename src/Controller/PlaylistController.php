<?php

namespace App\Controller;

use App\Entity\PlaylistDefinition;
use App\Navidrome\NavidromeRepository;
use App\Repository\PlaylistDefinitionRepository;
use App\Service\M3uExporter;
use App\Service\PlaylistStatsService;
use App\Subsonic\SubsonicClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * UI for managing Subsonic playlists themselves (as opposed to
 * PlaylistDefinition entities). All mutations go through SubsonicClient
 * because the Navidrome DB is mounted read-only in production.
 */
class PlaylistController extends AbstractController
{
    #[Route('/playlists', name: 'app_playlists_index', methods: ['GET'])]
    public function index(SubsonicClient $subsonic): Response
    {
        try {
            $playlists = $subsonic->getPlaylists();
        } catch (\Throwable $e) {
            $this->addFlash('error', sprintf('Impossible de récupérer les playlists Navidrome : %s', $e->getMessage()));
            $playlists = [];
        }

        usort($playlists, static fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return $this->render('playlist_management/index.html.twig', [
            'playlists' => $playlists,
        ]);
    }

    #[Route('/playlists/{id}', name: 'app_playlists_show', methods: ['GET'], requirements: ['id' => '[^/]+'])]
    public function show(
        string $id,
        SubsonicClient $subsonic,
        NavidromeRepository $navidrome,
        PlaylistStatsService $stats,
    ): Response {
        try {
            $playlist = $subsonic->getPlaylist($id);
        } catch (\Throwable $e) {
            $this->addFlash('error', sprintf('Playlist introuvable : %s', $e->getMessage()));
            return $this->redirectToRoute('app_playlists_index');
        }

        $trackIds = array_values(array_filter(
            array_map(static fn (array $t) => $t['id'], $playlist['tracks']),
            static fn (string $tid) => $tid !== '',
        ));

        $missingIds = $navidrome->isAvailable()
            ? $navidrome->filterMissingMediaFileIds($trackIds)
            : [];

        return $this->render('playlist_management/show.html.twig', [
            'playlist' => $playlist,
            'stats' => $stats->compute($playlist['tracks']),
            'missingIds' => array_fill_keys($missingIds, true),
        ]);
    }

    #[Route('/playlists/{id}/rename', name: 'app_playlists_rename', methods: ['POST'], requirements: ['id' => '[^/]+'])]
    public function rename(string $id, Request $request, SubsonicClient $subsonic): Response
    {
        if (!$this->isCsrfTokenValid('rename' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') {
            $this->addFlash('error', 'Le nom de playlist ne peut pas être vide.');
            return $this->redirectToRoute('app_playlists_show', ['id' => $id]);
        }

        try {
            $subsonic->updatePlaylist($id, name: $name);
            $this->addFlash('success', sprintf('Playlist renommée en « %s ».', $name));
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Erreur Subsonic : ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_playlists_show', ['id' => $id]);
    }

    #[Route('/playlists/{id}/delete', name: 'app_playlists_delete', methods: ['POST'], requirements: ['id' => '[^/]+'])]
    public function delete(
        string $id,
        Request $request,
        SubsonicClient $subsonic,
        PlaylistDefinitionRepository $defs,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('delete' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        try {
            $subsonic->deletePlaylist($id);
            $this->detachFromDefinitions($id, $defs, $em);
            $this->addFlash('success', 'Playlist supprimée côté Navidrome.');
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Erreur Subsonic : ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_playlists_index');
    }

    #[Route('/playlists/bulk-delete', name: 'app_playlists_bulk_delete', methods: ['POST'])]
    public function bulkDelete(
        Request $request,
        SubsonicClient $subsonic,
        PlaylistDefinitionRepository $defs,
        EntityManagerInterface $em,
    ): Response {
        if (!$this->isCsrfTokenValid('bulk-delete', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $ids = array_values(array_filter(
            array_map('strval', (array) $request->request->all('ids')),
            static fn (string $i) => $i !== '',
        ));
        if ($ids === []) {
            $this->addFlash('error', 'Aucune playlist sélectionnée.');
            return $this->redirectToRoute('app_playlists_index');
        }

        $ok = 0;
        $errors = [];
        foreach ($ids as $id) {
            try {
                $subsonic->deletePlaylist($id);
                $this->detachFromDefinitions($id, $defs, $em);
                $ok++;
            } catch (\Throwable $e) {
                $errors[] = $id . ': ' . $e->getMessage();
            }
        }

        if ($ok > 0) {
            $this->addFlash('success', sprintf('%d playlist(s) supprimée(s).', $ok));
        }
        if ($errors !== []) {
            $this->addFlash('error', sprintf('%d échec(s) : %s', count($errors), implode(' / ', $errors)));
        }

        return $this->redirectToRoute('app_playlists_index');
    }

    #[Route('/playlists/{id}/duplicate', name: 'app_playlists_duplicate', methods: ['POST'], requirements: ['id' => '[^/]+'])]
    public function duplicate(string $id, Request $request, SubsonicClient $subsonic): Response
    {
        if (!$this->isCsrfTokenValid('duplicate' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        try {
            $src = $subsonic->getPlaylist($id);
            $songIds = array_values(array_filter(
                array_map(static fn (array $t) => $t['id'], $src['tracks']),
                static fn (string $tid) => $tid !== '',
            ));
            $name = $this->buildCopyName($subsonic, $src['name']);
            $newId = $subsonic->createPlaylist($name, $songIds);
            $this->addFlash('success', sprintf('Playlist dupliquée : « %s ».', $name));

            return $this->redirectToRoute('app_playlists_show', ['id' => $newId]);
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Erreur Subsonic : ' . $e->getMessage());

            return $this->redirectToRoute('app_playlists_show', ['id' => $id]);
        }
    }

    #[Route('/playlists/{id}/tracks/{songId}/toggle-star', name: 'app_playlists_toggle_star', methods: ['POST'], requirements: ['id' => '[^/]+', 'songId' => '[^/]+'])]
    public function toggleStar(string $id, string $songId, Request $request, SubsonicClient $subsonic): Response
    {
        if (!$this->isCsrfTokenValid('toggle-star' . $songId, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $unstar = (bool) $request->request->get('unstar', false);

        try {
            if ($unstar) {
                $subsonic->unstarTracks($songId);
            } else {
                $subsonic->starTracks($songId);
            }
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Erreur Subsonic : ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_playlists_show', ['id' => $id]);
    }

    #[Route('/playlists/{id}/star-all', name: 'app_playlists_star_all', methods: ['POST'], requirements: ['id' => '[^/]+'])]
    public function starAll(string $id, Request $request, SubsonicClient $subsonic): Response
    {
        if (!$this->isCsrfTokenValid('star-all' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $unstar = (bool) $request->request->get('unstar', false);

        try {
            $playlist = $subsonic->getPlaylist($id);
            $ids = [];
            foreach ($playlist['tracks'] as $t) {
                if ($t['id'] === '') {
                    continue;
                }
                $isStarred = $t['starred'] !== null && $t['starred'] !== '';
                if ($unstar ? $isStarred : !$isStarred) {
                    $ids[] = $t['id'];
                }
            }

            if ($ids === []) {
                $this->addFlash('info', $unstar ? 'Aucun morceau starré à retirer.' : 'Tous les morceaux sont déjà starrés.');
            } else {
                if ($unstar) {
                    $subsonic->unstarTracks(...$ids);
                    $this->addFlash('success', sprintf('%d morceau(x) dé-starré(s).', count($ids)));
                } else {
                    $subsonic->starTracks(...$ids);
                    $this->addFlash('success', sprintf('%d morceau(x) starré(s).', count($ids)));
                }
            }
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Erreur Subsonic : ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_playlists_show', ['id' => $id]);
    }

    #[Route('/playlists/{id}/remove-track/{position}', name: 'app_playlists_remove_track', methods: ['POST'], requirements: ['id' => '[^/]+', 'position' => '\d+'])]
    public function removeTrack(string $id, int $position, Request $request, SubsonicClient $subsonic): Response
    {
        if (!$this->isCsrfTokenValid('remove-track' . $id . ':' . $position, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        try {
            $subsonic->updatePlaylist($id, songIndexToRemove: [$position]);
            $this->addFlash('success', 'Morceau retiré de la playlist.');
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Erreur Subsonic : ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_playlists_show', ['id' => $id]);
    }

    #[Route('/playlists/{id}/purge-dead', name: 'app_playlists_purge_dead', methods: ['POST'], requirements: ['id' => '[^/]+'])]
    public function purgeDead(
        string $id,
        Request $request,
        SubsonicClient $subsonic,
        NavidromeRepository $navidrome,
    ): Response {
        if (!$this->isCsrfTokenValid('purge-dead' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        if (!$navidrome->isAvailable()) {
            $this->addFlash('error', 'DB Navidrome inaccessible — purge impossible.');
            return $this->redirectToRoute('app_playlists_show', ['id' => $id]);
        }

        try {
            $playlist = $subsonic->getPlaylist($id);
            $missing = $navidrome->filterMissingMediaFileIds(array_map(
                static fn (array $t) => $t['id'],
                $playlist['tracks'],
            ));
            if ($missing === []) {
                $this->addFlash('info', 'Aucun morceau mort à purger.');
                return $this->redirectToRoute('app_playlists_show', ['id' => $id]);
            }

            // Compute zero-based positions to remove. We iterate from the
            // last position to the first so Subsonic's index shifts don't
            // throw the next removal off.
            $missingSet = array_fill_keys($missing, true);
            $positions = [];
            foreach ($playlist['tracks'] as $i => $t) {
                if (isset($missingSet[$t['id']])) {
                    $positions[] = $i;
                }
            }
            rsort($positions);

            $subsonic->updatePlaylist($id, songIndexToRemove: $positions);
            $this->addFlash('success', sprintf('%d morceau(x) mort(s) retiré(s).', count($positions)));
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Erreur Subsonic : ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_playlists_show', ['id' => $id]);
    }

    #[Route('/playlists/{id}/export.m3u', name: 'app_playlists_export_m3u', methods: ['GET'], requirements: ['id' => '[^/]+'])]
    public function exportM3u(string $id, SubsonicClient $subsonic, M3uExporter $exporter): Response
    {
        $playlist = $subsonic->getPlaylist($id);
        $body = $exporter->export($playlist['tracks']);

        $response = new Response($body, Response::HTTP_OK, [
            'Content-Type' => 'audio/x-mpegurl; charset=utf-8',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $exporter->filenameFor($playlist['name'])),
        ]);

        return $response;
    }

    private function detachFromDefinitions(
        string $playlistId,
        PlaylistDefinitionRepository $defs,
        EntityManagerInterface $em,
    ): void {
        foreach ($defs->findBy(['lastSubsonicPlaylistId' => $playlistId]) as $def) {
            /** @var PlaylistDefinition $def */
            $def->setLastSubsonicPlaylistId(null);
        }
        $em->flush();
    }

    private function buildCopyName(SubsonicClient $subsonic, string $base): string
    {
        $existing = [];
        foreach ($subsonic->getPlaylists() as $p) {
            $existing[$p['name']] = true;
        }

        $candidate = $base . ' (copie)';
        if (!isset($existing[$candidate])) {
            return $candidate;
        }
        for ($i = 2; $i < 100; $i++) {
            $candidate = sprintf('%s (copie %d)', $base, $i);
            if (!isset($existing[$candidate])) {
                return $candidate;
            }
        }

        return $base . ' (copie ' . uniqid() . ')';
    }
}
