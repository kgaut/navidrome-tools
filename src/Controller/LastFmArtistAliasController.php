<?php

namespace App\Controller;

use App\Entity\LastFmArtistAlias;
use App\Form\LastFmArtistAliasType;
use App\Repository\LastFmArtistAliasRepository;
use App\Repository\LastFmMatchCacheRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LastFmArtistAliasController extends AbstractController
{
    private const PER_PAGE = 25;

    #[Route('/lastfm/artist-aliases', name: 'app_lastfm_artist_alias_index', methods: ['GET'])]
    public function index(Request $request, LastFmArtistAliasRepository $repo): Response
    {
        $query = trim((string) $request->query->get('q', ''));
        $page = max(1, (int) $request->query->get('page', 1));

        $aliases = $repo->search($query, $page, self::PER_PAGE);
        $total = $repo->countSearch($query);
        $totalPages = (int) max(1, ceil($total / self::PER_PAGE));

        return $this->render('lastfm/artist_aliases/index.html.twig', [
            'aliases' => $aliases,
            'total' => $total,
            'page' => $page,
            'total_pages' => $totalPages,
            'filters' => ['q' => $query],
        ]);
    }

    #[Route('/lastfm/artist-aliases/new', name: 'app_lastfm_artist_alias_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        LastFmArtistAliasRepository $repo,
        EntityManagerInterface $em,
        LastFmMatchCacheRepository $cacheRepo,
    ): Response {
        // Pre-fill from query string when coming from the « Aliaser l'artiste »
        // button — passed as a FormType option so it wires into the field's
        // initial `data`. Setting `data` via the form builder takes precedence
        // over post-creation `setData()` calls, hence this route.
        $prefillSource = $request->isMethod('GET')
            ? trim((string) $request->query->get('source_artist', ''))
            : '';

        $form = $this->createForm(LastFmArtistAliasType::class, null, [
            'alias' => null,
            'prefill_source_artist' => $prefillSource,
        ]);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $sourceArtist = (string) $form->get('source_artist')->getData();
            $targetArtist = (string) $form->get('target_artist')->getData();

            $existing = $repo->findBySourceArtist($sourceArtist);
            if ($existing !== null) {
                $this->addFlash('error', sprintf(
                    'Un alias existe déjà pour « %s » → « %s ». Modifiez-le au lieu d\'en créer un nouveau.',
                    $existing->getSourceArtist(),
                    $existing->getTargetArtist(),
                ));

                return $this->redirectToRoute('app_lastfm_artist_alias_edit', ['id' => $existing->getId()]);
            }

            $alias = new LastFmArtistAlias($sourceArtist, $targetArtist);
            try {
                $em->persist($alias);
                $em->flush();
            } catch (UniqueConstraintViolationException) {
                $this->addFlash('error', 'Un alias normalisé identique existe déjà — modifiez-le.');

                return $this->redirectToRoute('app_lastfm_artist_alias_index', ['q' => $sourceArtist]);
            }

            // Drop every cache row keyed under the alias source — the
            // matcher now rewrites those scrobbles to `$targetArtist`
            // before the cache lookup, so the old rows are unreachable
            // dead weight (and might mislead a future delete-alias
            // resurrection if we kept them).
            $cacheRepo->purgeByArtist($sourceArtist);
            $em->flush();

            $this->addFlash('success', sprintf(
                'Alias artiste créé : « %s » → « %s ». Lancez « Re-tenter le matching » pour ré-essayer les unmatched.',
                $sourceArtist,
                $targetArtist,
            ));

            return $this->redirectToRoute('app_lastfm_artist_alias_index');
        }

        return $this->render('lastfm/artist_aliases/form.html.twig', [
            'form' => $form,
            'alias' => null,
        ]);
    }

    #[Route('/lastfm/artist-aliases/{id}/edit', name: 'app_lastfm_artist_alias_edit', methods: ['GET', 'POST'])]
    public function edit(
        int $id,
        Request $request,
        LastFmArtistAliasRepository $repo,
        EntityManagerInterface $em,
        LastFmMatchCacheRepository $cacheRepo,
    ): Response {
        $alias = $repo->find($id);
        if ($alias === null) {
            throw $this->createNotFoundException('Alias introuvable.');
        }

        $form = $this->createForm(LastFmArtistAliasType::class, null, [
            'alias' => $alias,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $sourceArtist = (string) $form->get('source_artist')->getData();
            $targetArtist = (string) $form->get('target_artist')->getData();

            $oldSourceArtist = $alias->getSourceArtist();

            $alias->setSource($sourceArtist);
            $alias->setTargetArtist($targetArtist);

            try {
                $em->flush();
            } catch (UniqueConstraintViolationException) {
                $this->addFlash('error', 'Un autre alias couvre déjà cet artiste source normalisé.');

                return $this->redirectToRoute('app_lastfm_artist_alias_index');
            }

            $cacheRepo->purgeByArtist($oldSourceArtist);
            if ($oldSourceArtist !== $sourceArtist) {
                $cacheRepo->purgeByArtist($sourceArtist);
            }
            $em->flush();

            $this->addFlash('success', sprintf('Alias artiste « %s » → « %s » mis à jour.', $sourceArtist, $targetArtist));

            return $this->redirectToRoute('app_lastfm_artist_alias_index');
        }

        return $this->render('lastfm/artist_aliases/form.html.twig', [
            'form' => $form,
            'alias' => $alias,
        ]);
    }

    #[Route('/lastfm/artist-aliases/{id}/delete', name: 'app_lastfm_artist_alias_delete', methods: ['POST'])]
    public function delete(int $id, Request $request, LastFmArtistAliasRepository $repo, EntityManagerInterface $em): Response
    {
        $alias = $repo->find($id);
        if ($alias === null) {
            throw $this->createNotFoundException('Alias introuvable.');
        }

        if (!$this->isCsrfTokenValid('delete-artist-alias-' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $label = sprintf('%s → %s', $alias->getSourceArtist(), $alias->getTargetArtist());
        $em->remove($alias);
        $em->flush();
        // Intentional no-op on the match cache. Rows keyed under the
        // alias *target* artist (e.g. « la ruda ») were created by
        // legitimate scrobbles whose original artist was already that
        // name — they remain correct after the alias is gone. Rows
        // under the alias *source* never existed once the alias started
        // rewriting them. Negative TTL handles any future drift.
        $this->addFlash('success', sprintf('Alias artiste « %s » supprimé.', $label));

        return $this->redirectToRoute('app_lastfm_artist_alias_index');
    }
}
