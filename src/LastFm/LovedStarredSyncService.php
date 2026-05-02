<?php

namespace App\LastFm;

use App\Navidrome\NavidromeRepository;
use App\Repository\LastFmAliasRepository;
use App\Subsonic\SubsonicClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Synchronises Last.fm "loved" tracks with Navidrome "starred" via the
 * Subsonic API. Adds-only in v1: a track present in one set but missing
 * from the other is propagated; nothing is ever unstarred / unloved.
 * Idempotent — re-running does nothing once both sets are in sync.
 *
 * Lookup chain to resolve a Last.fm loved track to a media_file_id:
 *   1. manual alias (#18)
 *   2. MBID
 *   3. (artist, title) couple
 * (Triplet via album is N/A here — Last.fm loved tracks don't carry
 * an album; same for fuzzy, which would be too risky for this sync.)
 */
class LovedStarredSyncService
{
    public const DIRECTION_LF_TO_ND = 'lf-to-nd';
    public const DIRECTION_ND_TO_LF = 'nd-to-lf';
    public const DIRECTION_BOTH = 'both';

    private LoggerInterface $logger;

    public function __construct(
        private readonly LastFmClient $lastFmClient,
        private readonly LastFmAuthService $authService,
        private readonly SubsonicClient $subsonic,
        private readonly NavidromeRepository $navidrome,
        private readonly LastFmAliasRepository $aliasRepository,
        private readonly string $apiKey,
        private readonly string $apiSecret,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function sync(string $direction = self::DIRECTION_BOTH, bool $dryRun = true): SyncReport
    {
        $this->logger->info('loved↔starred sync starting', ['direction' => $direction, 'dry_run' => $dryRun]);
        if (!in_array($direction, [self::DIRECTION_LF_TO_ND, self::DIRECTION_ND_TO_LF, self::DIRECTION_BOTH], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown sync direction "%s".', $direction));
        }
        if ($this->apiKey === '' || $this->apiSecret === '') {
            throw new \RuntimeException('LASTFM_API_KEY and LASTFM_API_SECRET must be set to run the loved↔starred sync.');
        }
        $sk = $this->authService->getStoredSessionKey();
        $sessionUser = $this->authService->getStoredSessionUser();
        if ($sk === null || $sessionUser === null) {
            throw new \RuntimeException('No Last.fm session — connect via /lastfm/connect first.');
        }

        $report = new SyncReport();

        // 1. Pull both sides.
        $loved = $this->collectLoved($sessionUser);
        $starred = $this->collectStarred();
        $report->lovedCount = count($loved);
        $report->starredCount = count($starred);

        // Index loved by normalized (artist, title) for the nd-to-lf
        // membership check later.
        $lovedIndex = [];
        foreach ($loved as $l) {
            $key = NavidromeRepository::normalize($l->artist) . "\0" . NavidromeRepository::normalize($l->title);
            $lovedIndex[$key] = true;
        }

        // Index starred media_file_ids for the lf-to-nd membership check.
        $starredIds = [];
        foreach ($starred as $s) {
            $starredIds[$s['id']] = true;
        }

        // Cheap intersection metric for the report — based on the
        // (artist_norm, title_norm) of starred entries.
        foreach ($starred as $s) {
            $key = NavidromeRepository::normalize($s['artist']) . "\0" . NavidromeRepository::normalize($s['title']);
            if (isset($lovedIndex[$key])) {
                $report->commonCount++;
            }
        }

        // 2. lf → nd : star in Navidrome the loved tracks that match a
        //    media_file but aren't already starred.
        if ($direction === self::DIRECTION_LF_TO_ND || $direction === self::DIRECTION_BOTH) {
            $toStar = [];
            foreach ($loved as $l) {
                $mediaFileId = $this->resolveLovedToMediaFile($l);
                if ($mediaFileId === null) {
                    $report->lovedUnmatched[] = [
                        'artist' => $l->artist,
                        'title' => $l->title,
                        'mbid' => $l->mbid,
                        'loved_at' => $l->lovedAt,
                    ];
                    continue;
                }
                if (isset($starredIds[$mediaFileId])) {
                    continue;
                }
                $toStar[] = $mediaFileId;
                $report->starredAdded[] = [
                    'media_file_id' => $mediaFileId,
                    'artist' => $l->artist,
                    'title' => $l->title,
                ];
                // Reflect the addition locally so duplicate loves don't
                // re-add (rare but Last.fm allows it).
                $starredIds[$mediaFileId] = true;
            }
            if (!$dryRun && $toStar !== []) {
                try {
                    $this->subsonic->starTracks(...$toStar);
                } catch (\Throwable $e) {
                    $report->errors[] = [
                        'action' => 'subsonic.star',
                        'artist' => '',
                        'title' => '',
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        // 3. nd → lf : love on Last.fm the starred tracks that aren't
        //    already loved.
        if ($direction === self::DIRECTION_ND_TO_LF || $direction === self::DIRECTION_BOTH) {
            foreach ($starred as $s) {
                if ($s['artist'] === '' || $s['title'] === '') {
                    continue;
                }
                $key = NavidromeRepository::normalize($s['artist']) . "\0" . NavidromeRepository::normalize($s['title']);
                if (isset($lovedIndex[$key])) {
                    continue;
                }
                $report->lovedAdded[] = [
                    'artist' => $s['artist'],
                    'title' => $s['title'],
                ];
                $lovedIndex[$key] = true;
                if (!$dryRun) {
                    try {
                        $this->lastFmClient->trackLove($this->apiKey, $this->apiSecret, $sk, $s['artist'], $s['title']);
                    } catch (\Throwable $e) {
                        // Roll back the lovedAdded entry if the call failed.
                        array_pop($report->lovedAdded);
                        $report->errors[] = [
                            'action' => 'lastfm.track.love',
                            'artist' => $s['artist'],
                            'title' => $s['title'],
                            'error' => $e->getMessage(),
                        ];
                    }
                }
            }
        }

        return $report;
    }

    /**
     * @return list<LastFmLovedTrack>
     */
    private function collectLoved(string $sessionUser): array
    {
        $out = [];
        foreach ($this->lastFmClient->iterateLovedTracks($this->apiKey, $sessionUser) as $loved) {
            $out[] = $loved;
        }

        return $out;
    }

    /**
     * @return list<array{id: string, title: string, artist: string, album: string}>
     */
    private function collectStarred(): array
    {
        return $this->subsonic->getStarred();
    }

    private function resolveLovedToMediaFile(LastFmLovedTrack $loved): ?string
    {
        // 1. Manual alias.
        $alias = $this->aliasRepository->findByScrobble($loved->artist, $loved->title);
        if ($alias !== null) {
            return $alias->isSkip() ? null : $alias->getTargetMediaFileId();
        }
        // 2. MBID.
        if ($loved->mbid !== null) {
            $id = $this->navidrome->findMediaFileByMbid($loved->mbid);
            if ($id !== null) {
                return $id;
            }
        }
        // 3. Couple. We skip the triplet (no album in loved) and fuzzy
        //    (too risky for an automatic sync — keep it for the import).
        return $this->navidrome->findMediaFileByArtistTitle($loved->artist, $loved->title);
    }
}
