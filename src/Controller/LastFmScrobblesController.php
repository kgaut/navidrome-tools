<?php

namespace App\Controller;

use App\Repository\ScrobbleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Last.fm scrobble history page — paginated, filterable view of every
 * scrobble landed in the local tools DB, with the Navidrome matching
 * status surfaced per row so the page doubles as a diagnostic view of
 * what hasn't been bridged to the library yet.
 *
 * Reads only the tools DB; never touches Navidrome write-side. Default
 * page returns the 100 most recent scrobbles for the configured user.
 */
class LastFmScrobblesController extends AbstractController
{
    private const PER_PAGE = 100;

    public function __construct(
        private readonly string $defaultUser,
    ) {
    }

    #[Route('/lastfm/scrobbles', name: 'app_lastfm_scrobbles', methods: ['GET'])]
    public function index(Request $request, ScrobbleRepository $scrobbles): Response
    {
        $user = $this->defaultUser !== '' ? $this->defaultUser : null;
        $page = max(1, (int) $request->query->get('page', 1));

        $filters = [
            'lastfm_user' => $user,
            'year' => self::stringOrNull($request->query->get('year')),
            'month' => self::stringOrNull($request->query->get('month')),
            'day' => self::stringOrNull($request->query->get('day')),
            'artist' => self::stringOrNull($request->query->get('artist')),
            'title' => self::stringOrNull($request->query->get('title')),
            'status' => self::stringOrNull($request->query->get('status')),
        ];

        $rows = $scrobbles->findRecentWithSyncStatus(
            filters: $filters,
            limit: self::PER_PAGE,
            offset: ($page - 1) * self::PER_PAGE,
        );
        $total = $scrobbles->countWithFilters($filters);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));

        return $this->render('lastfm/scrobbles.html.twig', [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'available_years' => $scrobbles->availableYears($user),
            'filters' => [
                'year' => $filters['year'] ?? '',
                'month' => $filters['month'] ?? '',
                'day' => $filters['day'] ?? '',
                'artist' => $filters['artist'] ?? '',
                'title' => $filters['title'] ?? '',
                'status' => $filters['status'] ?? '',
            ],
        ]);
    }

    private static function stringOrNull(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $v = trim($v);

        return $v === '' ? null : $v;
    }
}
