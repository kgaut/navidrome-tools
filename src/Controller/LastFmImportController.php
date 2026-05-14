<?php

namespace App\Controller;

use App\Message\FetchLastFmMessage;
use App\Repository\ScrobbleRepository;
use App\Repository\SettingRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

class LastFmImportController extends AbstractController
{
    private const SETTING_KEY_PREFIX = 'lastfm_last_fetch_';

    public function __construct(
        private readonly string $defaultApiKey,
        private readonly string $defaultUser,
    ) {
    }

    #[Route('/lastfm/import', name: 'app_lastfm_import', methods: ['GET'])]
    public function index(
        ScrobbleRepository $scrobbles,
        SettingRepository $settings,
    ): Response {
        $user = $this->defaultUser;
        $lastFetch = $user !== '' ? $settings->get(self::SETTING_KEY_PREFIX . $user) : '';

        return $this->render('lastfm/import.html.twig', [
            'total_scrobbles' => $scrobbles->countAll(),
            'user_scrobbles' => $user !== '' ? $scrobbles->countByUser($user) : null,
            'last_fetch' => $lastFetch !== '' ? new \DateTimeImmutable($lastFetch) : null,
            'default_user' => $user,
            'default_api_key' => $this->defaultApiKey,
        ]);
    }

    #[Route('/lastfm/import', name: 'app_lastfm_import_post', methods: ['POST'])]
    public function fetch(Request $request, MessageBusInterface $bus): Response
    {
        if (!$this->isCsrfTokenValid('lastfm_fetch', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $user = trim((string) $request->request->get('user', $this->defaultUser));
        $apiKey = trim((string) $request->request->get('api_key', $this->defaultApiKey));
        $dateMin = trim((string) $request->request->get('date_min', '')) ?: null;
        $dateMax = trim((string) $request->request->get('date_max', '')) ?: null;
        $maxScrobbles = ($v = $request->request->get('max_scrobbles')) ? (int) $v : null;
        $dryRun = (bool) $request->request->get('dry_run');

        if ($user === '' || $apiKey === '') {
            $this->addFlash('error', 'Utilisateur et API key requis.');
            return $this->redirectToRoute('app_lastfm_import');
        }

        $message = new FetchLastFmMessage(
            user: $user,
            apiKey: $apiKey,
            dateMin: $dateMin,
            dateMax: $dateMax,
            maxScrobbles: $maxScrobbles,
            dryRun: $dryRun,
        );

        $bus->dispatch($message);

        $this->addFlash('success', sprintf(
            'Fetch Last.fm pour « %s » lancé en arrière-plan. Suivez la progression dans l\'historique.',
            $user,
        ));

        return $this->redirectToRoute('app_history');
    }
}
