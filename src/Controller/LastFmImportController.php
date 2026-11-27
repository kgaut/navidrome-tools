<?php

namespace App\Controller;

use App\Message\FetchScrobblesMessage;
use App\Repository\ScrobbleRepository;
use App\Scrobble\ScrobbleFetchRunner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Import page: fetch listens from the active source (`SCROBBLE_SOURCE`,
 * issue #310). Credentials come from the configuration only.
 */
class LastFmImportController extends AbstractController
{
    public function __construct(
        private readonly ScrobbleFetchRunner $runner,
    ) {
    }

    #[Route('/listens/import', name: 'app_lastfm_import', methods: ['GET'])]
    public function index(ScrobbleRepository $scrobbles): Response
    {
        try {
            $source = $this->runner->source();
            $error = null;
        } catch (\InvalidArgumentException $e) {
            $source = null;
            $error = $e->getMessage();
        }

        return $this->render('lastfm/import.html.twig', [
            'total_scrobbles' => $scrobbles->countAll(),
            'source_name' => $source?->name(),
            'source_user' => $source?->user(),
            'source_error' => $error,
            'last_fetch' => $this->runner->lastFetchAt(),
        ]);
    }

    #[Route('/listens/import', name: 'app_lastfm_import_post', methods: ['POST'])]
    public function fetch(Request $request, MessageBusInterface $bus): Response
    {
        if (!$this->isCsrfTokenValid('lastfm_fetch', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        try {
            $source = $this->runner->source();
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
            return $this->redirectToRoute('app_lastfm_import');
        }

        $bus->dispatch(new FetchScrobblesMessage(
            dateMin: trim((string) $request->request->get('date_min', '')) ?: null,
            dateMax: trim((string) $request->request->get('date_max', '')) ?: null,
            maxScrobbles: ($v = $request->request->get('max_scrobbles')) ? (int) $v : null,
            dryRun: (bool) $request->request->get('dry_run'),
        ));

        $this->addFlash('success', sprintf(
            'Fetch %s pour « %s » lancé en arrière-plan. Suivez la progression dans l\'historique.',
            $source->name(),
            $source->user(),
        ));

        return $this->redirectToRoute('app_history');
    }
}
