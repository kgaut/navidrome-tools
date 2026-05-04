<?php

namespace App\Controller;

use App\Docker\NavidromeContainerException;
use App\Docker\NavidromeContainerManager;
use App\Entity\RunHistory;
use App\Form\LastFmImportType;
use App\Lidarr\LidarrConfig;
use App\Message\RunLastFmFetchMessage;
use App\Message\RunLastFmProcessMessage;
use App\Repository\LastFmBufferedScrobbleRepository;
use App\Repository\LastFmImportTrackRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

class LastFmImportController extends AbstractController
{
    public function __construct(
        private readonly LidarrConfig $lidarrConfig,
        private readonly LastFmImportTrackRepository $trackRepo,
        private readonly LastFmBufferedScrobbleRepository $bufferRepo,
        private readonly NavidromeContainerManager $containerManager,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly string $navidromeUrl,
    ) {
    }

    #[Route('/lastfm/import', name: 'app_lastfm_import', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $defaultUser = (string) ($_ENV['LASTFM_USER'] ?? getenv('LASTFM_USER') ?: '');
        $form = $this->createForm(LastFmImportType::class, $defaultUser !== '' ? ['lastfm_user' => $defaultUser] : null);
        $form->handleRequest($request);

        $error = null;

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array<string, mixed> $data */
            $data = $form->getData();
            $apiKey = (string) ($data['api_key'] ?? '');
            if ($apiKey === '') {
                $apiKey = (string) ($_ENV['LASTFM_API_KEY'] ?? getenv('LASTFM_API_KEY') ?: '');
            }

            if ($apiKey === '') {
                $error = 'Aucune API key fournie (champ vide et LASTFM_API_KEY non défini).';
            }

            $user = (string) ($data['lastfm_user'] ?? '');
            $isDry = (bool) ($data['dry_run'] ?? false);

            if ($error === null) {
                $dateMin = $data['date_min'] instanceof \DateTimeInterface
                    ? \DateTimeImmutable::createFromInterface($data['date_min'])
                    : null;
                $dateMax = $data['date_max'] instanceof \DateTimeInterface
                    ? \DateTimeImmutable::createFromInterface($data['date_max'])
                    : null;
                $maxScrobbles = $data['max_scrobbles'] !== null ? max(1, (int) $data['max_scrobbles']) : null;

                $entry = new RunHistory(
                    type: RunHistory::TYPE_LASTFM_FETCH,
                    reference: $user,
                    label: 'Last.fm fetch — ' . $user . ($isDry ? ' [dry-run]' : ''),
                );
                $entry->setStatus(RunHistory::STATUS_QUEUED);
                $this->em->persist($entry);
                $this->em->flush();

                $this->bus->dispatch(new RunLastFmFetchMessage(
                    runHistoryId: (int) $entry->getId(),
                    apiKey: $apiKey,
                    lastFmUser: $user,
                    dateMin: $dateMin?->format('Y-m-d H:i:s'),
                    dateMax: $dateMax?->format('Y-m-d H:i:s'),
                    maxScrobbles: $maxScrobbles,
                    dryRun: $isDry,
                ));

                $this->addFlash('success', 'Fetch Last.fm mis en file — la progression s\'affiche ci-dessous.');

                return new RedirectResponse($this->generateUrl('app_history_detail', ['id' => $entry->getId()]));
            }
        }

        $containerStatus = $this->containerManager->getStatus();

        return $this->render('lastfm/import.html.twig', [
            'form' => $form->createView(),
            'error' => $error,
            'unmatched_cumulative' => $this->trackRepo->countUnmatched(),
            'buffer_count' => $this->bufferRepo->countAll(),
            'lidarr_configured' => $this->lidarrConfig->isConfigured(),
            'navidrome_url' => rtrim($this->navidromeUrl, '/'),
            'container_configured' => $this->containerManager->isConfigured(),
            'container_status' => $containerStatus->value,
        ]);
    }

    #[Route('/lastfm/process', name: 'app_lastfm_process', methods: ['POST'])]
    public function process(Request $request): Response
    {
        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('lastfm_process', $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $this->containerManager->assertSafeToWrite();
        } catch (NavidromeContainerException $e) {
            $this->addFlash('error', $e->getMessage());

            return new RedirectResponse($this->generateUrl('app_lastfm_import'));
        }

        $entry = new RunHistory(
            type: RunHistory::TYPE_LASTFM_PROCESS,
            reference: 'buffer',
            label: 'Last.fm process buffer',
        );
        $entry->setStatus(RunHistory::STATUS_QUEUED);
        $this->em->persist($entry);
        $this->em->flush();

        $this->bus->dispatch(new RunLastFmProcessMessage(runHistoryId: (int) $entry->getId()));

        $this->addFlash('success', 'Traitement du buffer mis en file — la progression s\'affiche ci-dessous.');

        return new RedirectResponse($this->generateUrl('app_history_detail', ['id' => $entry->getId()]));
    }
}
