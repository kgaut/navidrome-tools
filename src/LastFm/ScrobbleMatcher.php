<?php

namespace App\LastFm;

use App\Navidrome\NavidromeRepository;
use App\Repository\LastFmAliasRepository;

/**
 * Encapsulates the matching cascade Last.fm scrobble → Navidrome media_file.
 * Used by both the live importer ({@see LastFmImporter}) and the rematch
 * service ({@see \App\Service\LastFmRematchService}) so the cascade lives in
 * one place.
 *
 * Cascade order (each step short-circuits on success) :
 *   1. Manual alias    → STATUS_MATCHED or STATUS_SKIPPED if alias targets null
 *   2. MBID            → STATUS_MATCHED
 *   3. Triplet (artist, title, album) when album is non-empty → STATUS_MATCHED
 *   4. Couple (artist, title) with strip-feat / strip-version-markers
 *   5. Fuzzy Levenshtein (opt-in via fuzzyMaxDistance > 0)
 *   → STATUS_UNMATCHED if everything fails.
 */
class ScrobbleMatcher
{
    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly ?LastFmAliasRepository $aliasRepository = null,
        private readonly int $fuzzyMaxDistance = 0,
    ) {
    }

    public function match(LastFmScrobble $scrobble): MatchResult
    {
        // Manual alias overrides every heuristic. A `null` target means
        // « ignore this scrobble silently » (skipped status).
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

        if ($scrobble->mbid !== null) {
            $mfid = $this->navidrome->findMediaFileByMbid($scrobble->mbid);
            if ($mfid !== null) {
                return MatchResult::matched($mfid);
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
                return MatchResult::matched($mfid);
            }
        }

        $mfid = $this->navidrome->findMediaFileByArtistTitle($scrobble->artist, $scrobble->title);
        if ($mfid !== null) {
            return MatchResult::matched($mfid);
        }

        // Last resort: fuzzy Levenshtein, opt-in via LASTFM_FUZZY_MAX_DISTANCE.
        if ($this->fuzzyMaxDistance > 0) {
            $mfid = $this->navidrome->findMediaFileFuzzy(
                $scrobble->artist,
                $scrobble->title,
                $this->fuzzyMaxDistance,
            );
            if ($mfid !== null) {
                return MatchResult::matched($mfid);
            }
        }

        return MatchResult::unmatched();
    }
}
