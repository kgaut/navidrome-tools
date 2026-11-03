<?php

namespace App\Service;

use App\Entity\ScrobbleSync;
use App\Navidrome\NavidromeRepository;
use App\Navidrome\NavidromeStringNormalizer;
use App\Repository\LastFmArtistAliasRepository;
use App\Repository\LastFmMatchCacheRepository;
use App\Repository\ScrobbleSyncRepository;
use App\Repository\SettingRepository;

/**
 * Puts unmatched scrobbles back to `pending` at the start of every
 * Navidrome sync, so the same run retries them (issue #278).
 *
 * Before this, an unmatched scrobble was never retried on its own: only a
 * manual rematch put it back. The negative-cache TTL promised a retry
 * « once the answer may have changed », but nothing re-read the rows.
 * Two triggers, both targeted:
 *
 *  1. Library grew — `MAX(media_file.created_at)` moved past the watermark
 *     stored at the previous run: every unmatched couple whose artist (full
 *     credit, feat-less, lead artist, or artist-alias target) belongs to an
 *     added artist, negative cache purged. The first run only records the
 *     watermark.
 *  2. Expired miss — unmatched couples with no negative cache entry younger
 *     than the TTL, most played first, at most `$expiredPerRun` couples per
 *     sync. A couple that misses again gets a fresh negative and waits one
 *     more TTL: the backlog is worked through in rotation, without a burst
 *     of Last.fm calls.
 */
class UnmatchedCatchUp
{
    public const SETTING_WATERMARK = 'navidrome.library_watermark';

    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly ScrobbleSyncRepository $syncRepo,
        private readonly LastFmMatchCacheRepository $cache,
        private readonly SettingRepository $settings,
        private readonly ?LastFmArtistAliasRepository $artistAliases = null,
        private readonly int $cacheTtlDays = 30,
        private readonly int $expiredPerRun = 200,
    ) {
    }

    /**
     * @return array{library_couples: int, expired_couples: int, requeued: int}
     */
    public function run(): array
    {
        $couples = $this->syncRepo->unmatchedCouples(ScrobbleSync::TARGET_NAVIDROME);
        $requeued = 0;

        $library = $this->afterLibraryChange($couples);
        foreach ($library as $couple) {
            $this->cache->purgeByCouple($couple['artist'], $couple['title']);
            $requeued += $this->requeue($couple);
        }

        $expired = $this->expired($couples, $library);
        foreach ($expired as $couple) {
            $requeued += $this->requeue($couple);
        }

        return [
            'library_couples' => count($library),
            'expired_couples' => count($expired),
            'requeued' => $requeued,
        ];
    }

    /**
     * Couples concerned by the library additions since the stored
     * watermark, which is moved forward.
     *
     * @param list<array{artist: string, title: string, mbid_albums: ?string, plays: int}> $couples
     *
     * @return array<string, array{artist: string, title: string}> keyed by couple
     */
    private function afterLibraryChange(array $couples): array
    {
        $since = $this->settings->get(self::SETTING_WATERMARK);
        $additions = $this->navidrome->libraryAdditionsSince($since !== '' ? $since : null);
        $watermark = $additions['watermark'];
        if ($watermark === null || $watermark === $since) {
            return [];
        }
        $this->settings->set(self::SETTING_WATERMARK, $watermark);

        $addedKeys = [];
        foreach ($additions['artists'] as $artist) {
            foreach (NavidromeStringNormalizer::artistKeys($artist) as $key) {
                $addedKeys[$key] = true;
            }
        }
        if ($addedKeys === []) {
            return [];
        }

        $out = [];
        foreach ($couples as $couple) {
            if ($this->concerns((string) $couple['artist'], $addedKeys)) {
                $out[self::key($couple)] = ['artist' => (string) $couple['artist'], 'title' => (string) $couple['title']];
            }
        }

        return $out;
    }

    /**
     * @param list<array{artist: string, title: string, mbid_albums: ?string, plays: int}> $couples (most played first)
     * @param array<string, mixed> $exclude
     *
     * @return list<array{artist: string, title: string}>
     */
    private function expired(array $couples, array $exclude): array
    {
        if ($this->expiredPerRun <= 0 || $this->cacheTtlDays <= 0) {
            return [];
        }

        $fresh = $this->cache->freshNegativeKeys($this->cacheTtlDays);
        $out = [];
        foreach ($couples as $couple) {
            if (count($out) >= $this->expiredPerRun) {
                break;
            }
            $artistN = NavidromeRepository::normalize((string) $couple['artist']);
            $titleN = NavidromeRepository::normalize((string) $couple['title']);
            if (isset($exclude[self::key($couple)]) || isset($fresh[$artistN . "\0" . $titleN])) {
                continue;
            }
            $out[] = ['artist' => (string) $couple['artist'], 'title' => (string) $couple['title']];
        }

        return $out;
    }

    /**
     * @param array{artist: string, title: string} $couple
     */
    private function requeue(array $couple): int
    {
        return $this->syncRepo->resetCoupleToPending(ScrobbleSync::TARGET_NAVIDROME, $couple['artist'], $couple['title']);
    }

    /**
     * @param array{artist: string, title: string} $couple
     */
    private static function key(array $couple): string
    {
        return $couple['artist'] . "\0" . $couple['title'];
    }

    /**
     * @param array<string, true> $addedKeys
     */
    private function concerns(string $artist, array $addedKeys): bool
    {
        $candidates = [$artist];
        $resolved = $this->artistAliases?->resolve($artist);
        if ($resolved !== null && $resolved !== '') {
            $candidates[] = $resolved;
        }
        foreach ($candidates as $candidate) {
            foreach (NavidromeStringNormalizer::artistKeys($candidate) as $key) {
                if (isset($addedKeys[$key])) {
                    return true;
                }
            }
        }

        return false;
    }
}
