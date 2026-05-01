<?php

namespace App\Controller;

use App\Entity\LastFmImportTrack;
use App\Entity\RunHistory;
use App\Form\LastFmImportType;
use App\LastFm\ImportReport;
use App\LastFm\LastFmImporter;
use App\LastFm\LastFmScrobble;
use App\Lidarr\LidarrConfig;
use App\Navidrome\NavidromeRepository;
use App\Service\RunHistoryRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LastFmImportController extends AbstractController
{
    public function __construct(
        private readonly LastFmImporter $importer,
        private readonly LidarrConfig $lidarrConfig,
        private readonly NavidromeRepository $navidrome,
        private readonly RunHistoryRecorder $recorder,
        private readonly EntityManagerInterface $em,
        private readonly string $navidromeUrl,
    ) {
    }

    #[Route('/lastfm/import', name: 'app_lastfm_import', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $defaultUser = (string) ($_ENV['LASTFM_USER'] ?? getenv('LASTFM_USER') ?: '');
        $form = $this->createForm(LastFmImportType::class, $defaultUser !== '' ? ['lastfm_user' => $defaultUser] : null);
        $form->handleRequest($request);

        $report = null;
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
            } else {
                set_time_limit(0);
                ignore_user_abort(true);
                $user = (string) $data['lastfm_user'];
                $isDry = (bool) ($data['dry_run'] ?? true);
                $dateMin = $data['date_min'] instanceof \DateTimeInterface
                    ? \DateTimeImmutable::createFromInterface($data['date_min'])
                    : null;
                $dateMax = $data['date_max'] instanceof \DateTimeInterface
                    ? \DateTimeImmutable::createFromInterface($data['date_max'])
                    : null;
                try {
                    $em = $this->em;
                    $report = $this->recorder->record(
                        type: RunHistory::TYPE_LASTFM_IMPORT,
                        reference: $user,
                        label: 'Last.fm import — ' . $user . ($isDry ? ' [dry-run]' : ''),
                        action: fn (RunHistory $entry) => $this->importer->import(
                            apiKey: $apiKey,
                            lastFmUser: $user,
                            dateMin: $dateMin,
                            dateMax: $dateMax,
                            toleranceSeconds: max(0, (int) ($data['tolerance'] ?? 60)),
                            dryRun: $isDry,
                            maxScrobbles: $data['max_scrobbles'] !== null ? max(1, (int) $data['max_scrobbles']) : null,
                            onScrobble: function (LastFmScrobble $s, string $status, ?string $mfid) use ($entry, $em): void {
                                $em->persist(new LastFmImportTrack(
                                    runHistory: $entry,
                                    artist: $s->artist,
                                    title: $s->title,
                                    album: $s->album,
                                    mbid: $s->mbid,
                                    playedAt: $s->playedAt,
                                    status: $status,
                                    matchedMediaFileId: $mfid,
                                ));
                                // RunHistoryRecorder::record flushes once at the end, which
                                // covers the persisted tracks too — no per-scrobble flush.
                            },
                        ),
                        extractMetrics: static fn (ImportReport $r) => [
                            'fetched' => $r->fetched,
                            'inserted' => $r->inserted,
                            'duplicates' => $r->duplicates,
                            'unmatched' => $r->unmatched,
                            'unmatched_artists' => $r->unmatchedArtistsRanking(100),
                            'dry_run' => $isDry,
                            'date_min' => $dateMin?->format('Y-m-d'),
                            'date_max' => $dateMax?->format('Y-m-d'),
                        ],
                    );
                } catch (\Throwable $e) {
                    $error = $e->getMessage();
                }
            }
        }

        $unmatched = [];
        if ($report instanceof ImportReport) {
            foreach ($report->unmatchedRanking(100) as $row) {
                $artist = $row['artist'];
                $unmatched[] = [
                    'count' => $row['count'],
                    'artist' => $artist,
                    'title' => $row['title'],
                    'album' => $row['album'],
                    'lastfm_url' => 'https://www.last.fm/music/' . rawurlencode($artist),
                    'navidrome_artist_id' => $artist !== '' ? $this->navidrome->findArtistIdByName($artist) : null,
                ];
            }
        }

        return $this->render('lastfm/import.html.twig', [
            'form' => $form->createView(),
            'report' => $report,
            'error' => $error,
            'unmatched' => $unmatched,
            'lidarr_configured' => $this->lidarrConfig->isConfigured(),
            'navidrome_url' => rtrim($this->navidromeUrl, '/'),
        ]);
    }
}
