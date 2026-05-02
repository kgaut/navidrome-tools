<?php

namespace App\Controller;

use App\Entity\LastFmAlias;
use App\Form\LastFmAliasType;
use App\Repository\LastFmAliasRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LastFmAliasController extends AbstractController
{
    private const PER_PAGE = 25;

    #[Route('/lastfm/aliases', name: 'app_lastfm_alias_index', methods: ['GET'])]
    public function index(Request $request, LastFmAliasRepository $repo): Response
    {
        $query = trim((string) $request->query->get('q', ''));
        $page = max(1, (int) $request->query->get('page', 1));

        $aliases = $repo->search($query, $page, self::PER_PAGE);
        $total = $repo->countSearch($query);
        $totalPages = (int) max(1, ceil($total / self::PER_PAGE));

        return $this->render('lastfm/aliases/index.html.twig', [
            'aliases' => $aliases,
            'total' => $total,
            'page' => $page,
            'total_pages' => $totalPages,
            'filters' => ['q' => $query],
        ]);
    }

    #[Route('/lastfm/aliases/new', name: 'app_lastfm_alias_new', methods: ['GET', 'POST'])]
    public function new(Request $request, LastFmAliasRepository $repo, EntityManagerInterface $em): Response
    {
        // Pre-fill from query string (« Mapper » button on /history/{id}
        // and /lastfm/unmatched). Passed as FormType options because the
        // field's `data` option in the builder takes precedence over
        // post-creation `setData()` calls.
        $prefillArtist = $request->isMethod('GET')
            ? trim((string) $request->query->get('source_artist', ''))
            : '';
        $prefillTitle = $request->isMethod('GET')
            ? trim((string) $request->query->get('source_title', ''))
            : '';

        $form = $this->createForm(LastFmAliasType::class, null, [
            'alias' => null,
            'prefill_source_artist' => $prefillArtist,
            'prefill_source_title' => $prefillTitle,
        ]);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $sourceArtist = (string) $form->get('source_artist')->getData();
            $sourceTitle = (string) $form->get('source_title')->getData();
            $skip = (bool) $form->get('skip')->getData();
            $target = $skip ? null : trim((string) $form->get('target_media_file_id')->getData());

            $existing = $repo->findByScrobble($sourceArtist, $sourceTitle);
            if ($existing !== null) {
                $this->addFlash('error', sprintf(
                    'Un alias existe déjà pour « %s — %s ». Modifiez-le au lieu d\'en créer un nouveau.',
                    $sourceArtist,
                    $sourceTitle,
                ));

                return $this->redirectToRoute('app_lastfm_alias_edit', ['id' => $existing->getId()]);
            }

            $alias = new LastFmAlias($sourceArtist, $sourceTitle, $target);
            try {
                $em->persist($alias);
                $em->flush();
            } catch (UniqueConstraintViolationException) {
                $this->addFlash('error', 'Un alias normalisé identique existe déjà — modifiez-le.');

                return $this->redirectToRoute('app_lastfm_alias_index', ['q' => $sourceArtist]);
            }

            $this->addFlash('success', sprintf(
                'Alias créé : « %s — %s » → %s.',
                $sourceArtist,
                $sourceTitle,
                $skip ? 'IGNORÉ' : ($target ?? ''),
            ));

            return $this->redirectToRoute('app_lastfm_alias_index');
        }

        return $this->render('lastfm/aliases/form.html.twig', [
            'form' => $form,
            'alias' => null,
        ]);
    }

    #[Route('/lastfm/aliases/{id}/edit', name: 'app_lastfm_alias_edit', methods: ['GET', 'POST'])]
    public function edit(int $id, Request $request, LastFmAliasRepository $repo, EntityManagerInterface $em): Response
    {
        $alias = $repo->find($id);
        if ($alias === null) {
            throw $this->createNotFoundException('Alias introuvable.');
        }

        $form = $this->createForm(LastFmAliasType::class, null, [
            'alias' => $alias,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $sourceArtist = (string) $form->get('source_artist')->getData();
            $sourceTitle = (string) $form->get('source_title')->getData();
            $skip = (bool) $form->get('skip')->getData();
            $target = $skip ? null : trim((string) $form->get('target_media_file_id')->getData());

            $alias->setSource($sourceArtist, $sourceTitle);
            $alias->setTargetMediaFileId($target);

            try {
                $em->flush();
            } catch (UniqueConstraintViolationException) {
                $this->addFlash('error', 'Un autre alias couvre déjà cette paire normalisée.');

                return $this->redirectToRoute('app_lastfm_alias_index');
            }

            $this->addFlash('success', sprintf('Alias « %s — %s » mis à jour.', $sourceArtist, $sourceTitle));

            return $this->redirectToRoute('app_lastfm_alias_index');
        }

        return $this->render('lastfm/aliases/form.html.twig', [
            'form' => $form,
            'alias' => $alias,
        ]);
    }

    #[Route('/lastfm/aliases/{id}/delete', name: 'app_lastfm_alias_delete', methods: ['POST'])]
    public function delete(int $id, Request $request, LastFmAliasRepository $repo, EntityManagerInterface $em): Response
    {
        $alias = $repo->find($id);
        if ($alias === null) {
            throw $this->createNotFoundException('Alias introuvable.');
        }

        if (!$this->isCsrfTokenValid('delete-alias-' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $label = sprintf('%s — %s', $alias->getSourceArtist(), $alias->getSourceTitle());
        $em->remove($alias);
        $em->flush();
        $this->addFlash('success', sprintf('Alias « %s » supprimé.', $label));

        return $this->redirectToRoute('app_lastfm_alias_index');
    }
}
