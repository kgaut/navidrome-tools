<?php

namespace App\Controller;

use App\Entity\ScrobbleSync;
use App\LastFm\LastFmScrobble;
use App\LastFm\MatchResult;
use App\LastFm\ScrobbleMatcher;
use App\Message\RematchMessage;
use App\Message\SuggestAliasesMusicBrainzMessage;
use App\Message\SyncNavidromeMessage;
use App\Navidrome\NavidromeRepository;
use App\Repository\LastFmMatchCacheRepository;
use App\Repository\ScrobbleSyncRepository;
use App\Service\DisparityStatsService;
use App\Service\UnmatchedDiagnoser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

class NavidromeSyncController extends AbstractController
{
    #[Route('/navidrome/sync', name: 'app_navidrome_sync', methods: ['GET'])]
    public function index(ScrobbleSyncRepository $syncRepo): Response
    {
        return $this->render('navidrome/sync.html.twig', [
            'pending' => $syncRepo->countPendingForTarget(ScrobbleSync::TARGET_NAVIDROME),
            'matched' => $syncRepo->countByTargetStatus(ScrobbleSync::TARGET_NAVIDROME, ScrobbleSync::STATUS_MATCHED),
            'unmatched' => $syncRepo->countByTargetStatus(ScrobbleSync::TARGET_NAVIDROME, ScrobbleSync::STATUS_UNMATCHED),
            'duplicates' => $syncRepo->countByTargetStatus(ScrobbleSync::TARGET_NAVIDROME, ScrobbleSync::STATUS_DUPLICATE),
        ]);
    }

    #[Route('/navidrome/sync', name: 'app_navidrome_sync_post', methods: ['POST'])]
    public function sync(Request $request, MessageBusInterface $bus): Response
    {
        if (!$this->isCsrfTokenValid('navidrome_sync', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $bus->dispatch(new SyncNavidromeMessage(
            limit: max(0, (int) $request->request->get('limit', 0)),
            dryRun: (bool) $request->request->get('dry_run'),
            toleranceSeconds: max(0, (int) $request->request->get('tolerance', 60)),
            autoStop: (bool) $request->request->get('auto_stop'),
        ));

        $this->addFlash('success', 'Sync Navidrome lancé en arrière-plan.');
        return $this->redirectToRoute('app_history');
    }

    #[Route('/navidrome/sync/reset', name: 'app_navidrome_sync_reset', methods: ['POST'])]
    public function reset(Request $request, ScrobbleSyncRepository $syncRepo): Response
    {
        if (!$this->isCsrfTokenValid('navidrome_sync_reset', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $reset = $syncRepo->resetAllToPending(ScrobbleSync::TARGET_NAVIDROME);
        $this->addFlash('success', sprintf('Reset Navidrome : %d ligne(s) remises en pending.', $reset));

        return $this->redirectToRoute('app_navidrome_sync');
    }

    #[Route('/navidrome/rematch', name: 'app_navidrome_rematch', methods: ['POST'])]
    public function rematch(Request $request, MessageBusInterface $bus): Response
    {
        if (!$this->isCsrfTokenValid('navidrome_rematch', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $bus->dispatch(new RematchMessage(
            target: ScrobbleSync::TARGET_NAVIDROME,
            limit: max(0, (int) $request->request->get('limit', 0)),
            dryRun: (bool) $request->request->get('dry_run'),
            autoStop: (bool) $request->request->get('auto_stop'),
        ));

        $this->addFlash('success', 'Rematch Navidrome lancé en arrière-plan.');
        return $this->redirectToRoute('app_history');
    }

    #[Route('/navidrome/suggest-aliases', name: 'app_navidrome_suggest_aliases', methods: ['POST'])]
    public function suggestAliases(Request $request, MessageBusInterface $bus): Response
    {
        if (!$this->isCsrfTokenValid('navidrome_suggest_aliases', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        // Default floor of 3 unmatched scrobbles per artist: only spend
        // (rate-limited) MusicBrainz calls on artists that actually move
        // the unmatched needle, per the issue's ≥3 threshold.
        $bus->dispatch(new SuggestAliasesMusicBrainzMessage(
            target: ScrobbleSync::TARGET_NAVIDROME,
            dryRun: (bool) $request->request->get('dry_run'),
            limit: max(0, (int) $request->request->get('limit', 0)),
            minPlays: max(1, (int) $request->request->get('min_plays', 3)),
        ));

        $this->addFlash(
            'success',
            'Suggestion d’alias MusicBrainz lancée en arrière-plan (throttle ~1 req/s). '
            . 'Les alias uniques haute-confiance sont appliqués automatiquement ; relancez un rematch ensuite.',
        );
        return $this->redirectToRoute('app_history');
    }

    /**
     * Per-track « retry match » (debug case-by-case). Purges the negative
     * cache for the couple, re-runs the matching cascade once on a synthetic
     * scrobble built from the aggregated row, then reports the outcome. On a
     * hit, the couple's unmatched rows are re-queued to pending (the actual
     * write into navidrome.db happens on the next sync/rematch). On a miss,
     * the diagnostic reason is surfaced. Reads Navidrome read-only → no
     * container stop.
     */
    #[Route('/navidrome/unmatched/retry', name: 'app_navidrome_unmatched_retry', methods: ['POST'])]
    public function retryMatch(
        Request $request,
        ScrobbleMatcher $matcher,
        ScrobbleSyncRepository $syncRepo,
        LastFmMatchCacheRepository $cache,
        UnmatchedDiagnoser $diagnoser,
        NavidromeRepository $navidrome,
    ): Response {
        if (!$this->isCsrfTokenValid('navidrome_unmatched_retry', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $artist = trim((string) $request->request->get('artist', ''));
        $title = trim((string) $request->request->get('title', ''));
        $album = trim((string) $request->request->get('album', ''));

        $back = $request->headers->get('referer') ?: $this->generateUrl('app_navidrome_unmatched');

        if ($artist === '' || $title === '') {
            $this->addFlash('error', 'Artiste ou titre manquant.');
            return $this->redirect($back);
        }

        // Purge the negative cache entry so the cascade actually re-runs
        // instead of short-circuiting on its own memoized miss.
        $cache->purgeByCouple($artist, $title);

        $result = $matcher->match(new LastFmScrobble(
            artist: $artist,
            title: $title,
            album: $album,
            mbid: null,
            playedAt: new \DateTimeImmutable(),
        ));

        if ($result->status === MatchResult::STATUS_MATCHED && $result->mediaFileId !== null) {
            $meta = $navidrome->getMediaFileMetadata([$result->mediaFileId]);
            $label = $meta !== []
                ? sprintf('%s — %s', $meta[0]['artist'], $meta[0]['album'] !== '' ? $meta[0]['album'] : '?')
                : $result->mediaFileId;
            $reset = $syncRepo->resetCoupleToPending(ScrobbleSync::TARGET_NAVIDROME, $artist, $title);
            $this->addFlash('success', sprintf(
                '« %s — %s » matché (stratégie %s → %s). %d scrobble(s) remis en pending : lancez un sync pour les insérer dans Navidrome.',
                $artist,
                $title,
                $result->strategy ?? '?',
                $label,
                $reset,
            ));
        } else {
            $diag = $diagnoser->diagnose($artist, $title);
            $reason = match ($diag['reason'] ?? '') {
                'artist_unknown' => 'artiste inconnu de la bibliothèque',
                'artist_near_match' => 'artiste proche (voir suggestions d’alias)',
                'title_near_match' => 'titre proche (voir suggestions d’alias)',
                'track_missing' => 'artiste présent mais titre absent de la bibliothèque',
                'matcher_gap' => 'track présente mais ratée par la cascade',
                default => 'inconnue',
            };
            $this->addFlash('warning', sprintf('« %s — %s » toujours non-matché. Raison : %s.', $artist, $title, $reason));
        }

        return $this->redirect($back);
    }

    #[Route('/navidrome/unmatched', name: 'app_navidrome_unmatched', methods: ['GET'])]
    public function unmatched(
        Request $request,
        ScrobbleSyncRepository $syncRepo,
        UnmatchedDiagnoser $diagnoser,
    ): Response {
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = 50;
        $filterArtist = trim((string) $request->query->get('artist', ''));
        $filterTitle = trim((string) $request->query->get('title', ''));
        $filterPeriod = self::normalizePeriod((string) $request->query->get('period', ''));

        $rows = $syncRepo->aggregateUnmatched(
            target: ScrobbleSync::TARGET_NAVIDROME,
            limit: $perPage,
            offset: ($page - 1) * $perPage,
            filterArtist: $filterArtist !== '' ? $filterArtist : null,
            filterTitle: $filterTitle !== '' ? $filterTitle : null,
            period: $filterPeriod,
        );
        // Per-row diagnosis is scoped to the current page (worst case 50
        // groups → a handful of cheap SQLite probes each). Cached in
        // {@see UnmatchedDiagnoser} would be premature: a user paginating
        // hits each row at most once per visit.
        foreach ($rows as &$row) {
            $row['diagnosis'] = $diagnoser->diagnose(
                (string) ($row['artist'] ?? ''),
                (string) ($row['title'] ?? ''),
            );
        }
        unset($row);
        $total = $syncRepo->countUnmatchedForTarget(ScrobbleSync::TARGET_NAVIDROME, $filterPeriod);

        return $this->render('navidrome/unmatched.html.twig', [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage)),
            'filter_artist' => $filterArtist,
            'filter_title' => $filterTitle,
            'filter_period' => $filterPeriod ?? '',
        ]);
    }

    #[Route('/navidrome/unmatched/stats', name: 'app_navidrome_unmatched_stats', methods: ['GET'])]
    public function unmatchedStats(
        ScrobbleSyncRepository $syncRepo,
        DisparityStatsService $disparity,
    ): Response {
        return $this->render('navidrome/unmatched_stats.html.twig', [
            'total' => $syncRepo->countUnmatchedForTarget(ScrobbleSync::TARGET_NAVIDROME),
            'top_artists' => $syncRepo->topUnmatchedArtists(ScrobbleSync::TARGET_NAVIDROME, 15),
            'top_albums' => $syncRepo->topUnmatchedAlbums(ScrobbleSync::TARGET_NAVIDROME, 15),
            'disparity' => $disparity->compute(),
        ]);
    }

    /**
     * Validate a period query param to `YYYY` or `YYYY-MM`, else null.
     */
    private static function normalizePeriod(string $period): ?string
    {
        $period = trim($period);

        return preg_match('/^\d{4}(-\d{2})?$/', $period) === 1 ? $period : null;
    }
}
