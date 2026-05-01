<?php

namespace App\Controller;

use App\Entity\LastFmImportTrack;
use App\Entity\RunHistory;
use App\Lidarr\LidarrClient;
use App\Lidarr\LidarrConfig;
use App\Repository\LastFmImportTrackRepository;
use App\Repository\RunHistoryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HistoryController extends AbstractController
{
    private const PER_PAGE = 50;
    private const DETAIL_TRACK_LIMIT = 500;

    public function __construct(
        private readonly LidarrConfig $lidarrConfig,
        private readonly LidarrClient $lidarrClient,
    ) {
    }

    #[Route('/history', name: 'app_history', methods: ['GET'])]
    public function index(Request $request, RunHistoryRepository $repository): Response
    {
        $filters = [
            'type' => $request->query->get('type'),
            'status' => $request->query->get('status'),
            'q' => $request->query->get('q'),
        ];
        $page = max(1, (int) $request->query->get('page', 1));

        $result = $repository->findFilteredPaginated($filters, $page, self::PER_PAGE);
        $totalPages = (int) ceil(max(1, $result['total']) / self::PER_PAGE);

        return $this->render('history/index.html.twig', [
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'total_pages' => $totalPages,
            'filters' => $filters,
            'types' => [
                RunHistory::TYPE_PLAYLIST => 'Playlist',
                RunHistory::TYPE_STATS => 'Stats',
                RunHistory::TYPE_LASTFM_IMPORT => 'Import Last.fm',
            ],
            'statuses' => [
                RunHistory::STATUS_SUCCESS => 'Succès',
                RunHistory::STATUS_ERROR => 'Erreur',
                RunHistory::STATUS_SKIPPED => 'Skip',
            ],
        ]);
    }

    #[Route('/history/{id}', name: 'app_history_detail', methods: ['GET'])]
    public function detail(RunHistory $entry, Request $request, LastFmImportTrackRepository $tracksRepo): Response
    {
        $tracks = [];
        $statusCounts = [];
        $statusFilter = null;
        $qFilter = null;
        $tracksTruncated = false;
        $unmatchedArtists = [];
        $lidarrReachable = true;

        if ($entry->getType() === RunHistory::TYPE_LASTFM_IMPORT) {
            $statusCounts = $tracksRepo->countByStatusForRun($entry);

            // Default to "unmatched" only if there are unmatched tracks for this
            // run; otherwise default to "all" so the page is not surprisingly
            // empty after a flawless import.
            $defaultStatus = ($statusCounts[LastFmImportTrack::STATUS_UNMATCHED] ?? 0) > 0
                ? LastFmImportTrack::STATUS_UNMATCHED
                : '';
            $statusFilter = $request->query->has('status')
                ? (string) $request->query->get('status')
                : $defaultStatus;

            if (!in_array($statusFilter, ['', LastFmImportTrack::STATUS_INSERTED, LastFmImportTrack::STATUS_DUPLICATE, LastFmImportTrack::STATUS_UNMATCHED], true)) {
                $statusFilter = '';
            }

            $qFilter = trim((string) $request->query->get('q', '')) ?: null;
            $tracks = $tracksRepo->findForRun(
                $entry,
                $statusFilter !== '' ? $statusFilter : null,
                $qFilter,
                self::DETAIL_TRACK_LIMIT,
            );
            $tracksTruncated = count($tracks) >= self::DETAIL_TRACK_LIMIT;

            $unmatchedArtists = $this->buildUnmatchedArtists($entry, $lidarrReachable);
        }

        return $this->render('history/detail.html.twig', [
            'entry' => $entry,
            'tracks' => $tracks,
            'tracks_truncated' => $tracksTruncated,
            'tracks_limit' => self::DETAIL_TRACK_LIMIT,
            'status_counts' => $statusCounts,
            'status_filter' => $statusFilter,
            'q_filter' => $qFilter,
            'track_statuses' => [
                LastFmImportTrack::STATUS_INSERTED => 'Insérés',
                LastFmImportTrack::STATUS_DUPLICATE => 'Doublons',
                LastFmImportTrack::STATUS_UNMATCHED => 'Non matchés',
            ],
            'unmatched_artists' => $unmatchedArtists,
            'lidarr_configured' => $this->lidarrConfig->isConfigured(),
            'lidarr_reachable' => $lidarrReachable,
        ]);
    }

    /**
     * @return list<array{artist: string, scrobbles: int, lidarr_url: string|null}>
     */
    private function buildUnmatchedArtists(RunHistory $entry, bool &$lidarrReachable): array
    {
        $metrics = $entry->getMetrics() ?? [];
        $raw = $metrics['unmatched_artists'] ?? [];
        if (!is_array($raw) || $raw === []) {
            return [];
        }

        $index = null;
        if ($this->lidarrConfig->isConfigured()) {
            try {
                $index = $this->lidarrClient->indexExistingArtists();
            } catch (\Throwable) {
                $lidarrReachable = false;
                $index = null;
            }
        }

        $rows = [];
        foreach ($raw as $row) {
            if (!is_array($row) || !isset($row['artist'])) {
                continue;
            }
            $artist = (string) $row['artist'];
            $key = mb_strtolower(trim($artist));
            $match = $index[$key] ?? null;
            $rows[] = [
                'artist' => $artist,
                'scrobbles' => (int) ($row['scrobbles'] ?? 0),
                'lidarr_url' => $match !== null && $match['foreignArtistId'] !== ''
                    ? $this->lidarrConfig->artistDetailUrl($match['foreignArtistId'])
                    : null,
            ];
        }

        return $rows;
    }
}
