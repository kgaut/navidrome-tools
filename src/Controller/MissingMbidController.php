<?php

namespace App\Controller;

use App\Navidrome\NavidromeRepository;
use App\Service\NavidromeRescanService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Audit page for Navidrome tracks whose MBID columns are empty —
 * the canonical input for an external tagger like beets / Picard.
 *
 * Architecture is deliberately decoupled: navidrome-tools never writes to
 * the music files (the volume can stay :ro). The page lists the tracks,
 * exports the absolute paths as CSV (which the user pipes into
 * `beet import -A …` on their tagger host), and exposes a "rescan"
 * button that triggers Navidrome via the Subsonic API once tagging is done
 * — so the new MBIDs propagate to media_file.mbz_track_id without waiting
 * for Navidrome's scheduled scan.
 */
class MissingMbidController extends AbstractController
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly NavidromeRescanService $rescan,
    ) {
    }

    #[Route('/tagging/missing-mbid', name: 'app_tagging_missing_mbid', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filters = [
            'artist' => trim((string) $request->query->get('artist', '')) ?: null,
            'album' => trim((string) $request->query->get('album', '')) ?: null,
        ];
        $page = max(1, (int) $request->query->get('page', 1));

        $total = $this->navidrome->countMediaFilesWithoutMbid(
            artistFilter: $filters['artist'],
            albumFilter: $filters['album'],
        );
        $totalPages = (int) max(1, ceil(max(1, $total) / self::PER_PAGE));
        $page = min($page, $totalPages);

        $rows = $this->navidrome->findMediaFilesWithoutMbid(
            artistFilter: $filters['artist'],
            albumFilter: $filters['album'],
            limit: self::PER_PAGE,
            offset: ($page - 1) * self::PER_PAGE,
        );

        return $this->render('tagging/missing_mbid.html.twig', [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'total_pages' => $totalPages,
            'filters' => array_filter($filters, static fn ($v) => $v !== null),
        ]);
    }

    /**
     * Streams the full unfiltered (or filtered, if query string is present)
     * list of paths as CSV so the user can pipe it into beets/Picard on
     * their tagger host. Format: one row per track with id, path, artist,
     * album, title, year. Limit clamped to 5000 to keep memory bounded.
     */
    #[Route('/tagging/missing-mbid/export.csv', name: 'app_tagging_missing_mbid_export', methods: ['GET'])]
    public function export(Request $request): StreamedResponse
    {
        $artistFilter = trim((string) $request->query->get('artist', '')) ?: null;
        $albumFilter = trim((string) $request->query->get('album', '')) ?: null;
        $limit = max(1, min(5000, (int) $request->query->get('limit', 1000)));

        $rows = $this->navidrome->findMediaFilesWithoutMbid(
            artistFilter: $artistFilter,
            albumFilter: $albumFilter,
            limit: $limit,
            offset: 0,
        );

        $response = new StreamedResponse(function () use ($rows): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['id', 'path', 'artist', 'album_artist', 'album', 'title', 'year'], ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['id'],
                    $row['path'],
                    $row['artist'],
                    $row['album_artist'],
                    $row['album'],
                    $row['title'],
                    $row['year'],
                ], ',', '"', '');
            }
            fclose($out);
        });
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', sprintf(
            'attachment; filename="missing-mbid-%s.csv"',
            (new \DateTimeImmutable())->format('Y-m-d'),
        ));

        return $response;
    }

    #[Route('/tagging/missing-mbid/rescan', name: 'app_tagging_missing_mbid_rescan', methods: ['POST'])]
    public function rescan(Request $request): Response
    {
        $token = (string) $request->request->get('_token', '');
        if (!$this->isCsrfTokenValid('missing_mbid_rescan', $token)) {
            $this->addFlash('error', 'Jeton CSRF invalide.');

            return $this->redirectToRoute('app_tagging_missing_mbid');
        }

        try {
            $this->rescan->rescan(reason: 'missing-mbid-page');
            $this->addFlash('success', 'Rescan Navidrome déclenché.');
        } catch (\Throwable $e) {
            $this->addFlash('error', 'Erreur déclenchement rescan : ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_tagging_missing_mbid');
    }
}
