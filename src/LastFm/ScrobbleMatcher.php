<?php

namespace App\LastFm;

use App\Entity\LastFmMatchCacheEntry;
use App\Navidrome\NavidromeRepository;
use App\Repository\LastFmAliasRepository;
use App\Repository\LastFmArtistAliasRepository;
use App\Repository\LastFmMatchCacheRepository;

/**
 * Encapsulates the matching cascade Last.fm scrobble → Navidrome media_file.
 * Used by both the live importer ({@see LastFmImporter}) and the rematch
 * service ({@see \App\Service\LastFmRematchService}) so the cascade lives in
 * one place.
 *
 * Cascade order (each step short-circuits on success) :
 *   1. Track-level manual alias → STATUS_MATCHED or STATUS_SKIPPED (skips cache)
 *   2. Artist-level alias       → rewrite artist name, fall through to next step
 *   3. Match cache lookup       → positive hit returns matched, negative
 *                                 (non-stale) hit returns unmatched. Skips
 *                                 the cascade so we don't re-run SQL or call
 *                                 Last.fm `track.getInfo` for known answers.
 *   4. MBID                     → STATUS_MATCHED
 *   5. Triplet (artist, title, album) when album is non-empty → STATUS_MATCHED
 *   6. Couple (artist, title) with strip-feat / strip-version-markers / etc.
 *   7. Fuzzy Levenshtein (opt-in via fuzzyMaxDistance > 0)
 *   → STATUS_UNMATCHED if everything fails. Both successes and failures are
 *   memoized in the cache so subsequent runs short-circuit at step 3.
 */
class ScrobbleMatcher
{
    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly ?LastFmAliasRepository $aliasRepository = null,
        private readonly int $fuzzyMaxDistance = 0,
        private readonly ?LastFmArtistAliasRepository $artistAliasRepository = null,
        private readonly ?LastFmMatchCacheRepository $cacheRepository = null,
        private readonly int $cacheTtlDays = 30,
    ) {
    }

    public function match(LastFmScrobble $scrobble): MatchResult
    {
        // Track-level manual alias overrides every heuristic (including
        // the cache). A `null` target means « ignore this scrobble silently »
        // (skipped). We don't write to the cache here — the alias is the
        // source of truth, no need to memoize.
        $alias = $this->aliasRepository?->findByScrobble($scrobble->artist, $scrobble->title);
        if ($alias !== null) {
            if ($alias->isSkip()) {
                return MatchResult::skipped();
            }
            $mfid = $alias->getTargetMediaFileId();
            if ($mfid !== null) {
                return MatchResult::matched($mfid);
            }
        }

        // Artist-level alias: rewrite the source artist before any
        // heuristic. Lets a single mapping cover ALL tracks of a renamed
        // artist (e.g. "La Ruda Salska" → "La Ruda" matches every La Ruda
        // track in the library without needing a per-track alias). We
        // re-key on the rewritten artist for the cache lookup below —
        // that's what the cascade sees, so the cache stays consistent.
        $resolvedArtist = $this->artistAliasRepository?->resolve($scrobble->artist);
        if ($resolvedArtist !== null && $resolvedArtist !== '' && $resolvedArtist !== $scrobble->artist) {
            $scrobble = new LastFmScrobble(
                artist: $resolvedArtist,
                title: $scrobble->title,
                album: $scrobble->album,
                mbid: $scrobble->mbid,
                playedAt: $scrobble->playedAt,
            );
        }

        // Match cache lookup. Positive entries are trusted forever (until
        // an alias mutation invalidates them) ; negatives expire after
        // `cacheTtlDays` so we eventually retry the cascade for scrobbles
        // whose answer might have changed (e.g. user added the track).
        if ($this->cacheRepository !== null) {
            $cached = $this->cacheRepository->findByCouple($scrobble->artist, $scrobble->title);
            if ($cached !== null) {
                if ($cached->isPositive()) {
                    /** @var string $mfid (positive entries always have a non-null target) */
                    $mfid = $cached->getTargetMediaFileId();

                    return MatchResult::matched($mfid, $cached->getStrategy(), MatchResult::CACHE_HIT_POSITIVE);
                }
                if (!$cached->isStale($this->cacheTtlDays)) {
                    return MatchResult::unmatched(MatchResult::CACHE_HIT_NEGATIVE);
                }
                // Stale negative: fall through to the cascade. The next
                // recordPositive/Negative below will overwrite this row.
            }
        }

        $cacheStatus = $this->cacheRepository !== null ? MatchResult::CACHE_MISS : null;
        [$mfid, $strategy] = $this->runCascade($scrobble);

        if ($mfid !== null && $strategy !== null) {
            $this->cacheRepository?->recordPositive($scrobble->artist, $scrobble->title, $mfid, $strategy);

            return MatchResult::matched($mfid, $strategy, $cacheStatus);
        }

        $this->cacheRepository?->recordNegative($scrobble->artist, $scrobble->title);

        return MatchResult::unmatched($cacheStatus);
    }

    /**
     * Runs the cascade past the alias / cache short-circuits. Returns
     * `[mediaFileId, strategy]` on a hit, or `[null, null]` on miss. The
     * strategy string matches one of `LastFmMatchCacheEntry::STRATEGY_*`
     * so `match()` can persist it as-is.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function runCascade(LastFmScrobble $scrobble): array
    {
        if ($scrobble->mbid !== null) {
            $mfid = $this->navidrome->findMediaFileByMbid($scrobble->mbid);
            if ($mfid !== null) {
                return [$mfid, LastFmMatchCacheEntry::STRATEGY_MBID];
            }
        }

        // Try the artist+title+album triplet before the bare couple — the
        // same song can live on a studio album AND a compilation AND a
        // single, and the album disambiguates which row the user played.
        if ($scrobble->album !== '') {
            $mfid = $this->navidrome->findMediaFileByArtistTitleAlbum(
                $scrobble->artist,
                $scrobble->title,
                $scrobble->album,
            );
            if ($mfid !== null) {
                return [$mfid, LastFmMatchCacheEntry::STRATEGY_TRIPLET];
            }
        }

        $mfid = $this->navidrome->findMediaFileByArtistTitle($scrobble->artist, $scrobble->title);
        if ($mfid !== null) {
            return [$mfid, LastFmMatchCacheEntry::STRATEGY_COUPLE];
        }

        // Last resort: fuzzy Levenshtein, opt-in via LASTFM_FUZZY_MAX_DISTANCE.
        if ($this->fuzzyMaxDistance > 0) {
            $mfid = $this->navidrome->findMediaFileFuzzy(
                $scrobble->artist,
                $scrobble->title,
                $this->fuzzyMaxDistance,
            );
            if ($mfid !== null) {
                return [$mfid, LastFmMatchCacheEntry::STRATEGY_FUZZY];
            }
        }

        return [null, null];
    }
}
