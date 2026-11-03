<?php

namespace App\Service;

use App\Navidrome\NavidromeRepository;
use App\Navidrome\NavidromeStringNormalizer;

/**
 * Classifies why a scrobble (or unmatched group) failed the matching
 * cascade, by re-running cheap read-only probes against the Navidrome DB.
 *
 * Read-only by design: this service does NOT mutate the cache, the alias
 * tables or anything else. Wired from the `/navidrome/unmatched` page so
 * users can see, per row, the likely category of failure (artist
 * unknown, near-match alias candidate, track missing from the library)
 * and act on it without guessing.
 *
 * The classification is intentionally heuristic and best-effort: it
 * mirrors the cascade's local-only steps (MBID excluded, since the
 * scrobble at this point already failed the full cascade including
 * `track.getInfo`).
 */
class UnmatchedDiagnoser
{
    public const REASON_ARTIST_UNKNOWN = 'artist_unknown';
    public const REASON_ARTIST_NEAR_MATCH = 'artist_near_match';
    public const REASON_MATCHER_GAP = 'matcher_gap';
    public const REASON_TITLE_NEAR_MATCH = 'title_near_match';
    public const REASON_TRACK_MISSING = 'track_missing';
    public const REASON_UNKNOWN = 'unknown';

    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly int $artistMaxDistance = 2,
        private readonly int $titleMaxDistance = 3,
    ) {
    }

    /**
     * @return array{
     *     reason: string,
     *     artist_suggestions?: list<array{name: string, distance: int}>,
     *     title_suggestions?: list<array{title: string, distance: int}>,
     *     target_media_file_id?: string,
     * }
     */
    public function diagnose(string $artist, string $title): array
    {
        if (trim($artist) === '' || trim($title) === '') {
            return ['reason' => self::REASON_UNKNOWN];
        }

        // Multi-artist / feat. credit (« Mobb Deep,The Alchemist ») unknown
        // as such: diagnose under the first library-known key — feat-less
        // credit, then lead artist (issue #280).
        if (!$this->navidrome->hasArtistInLibrary($artist)) {
            foreach (NavidromeStringNormalizer::artistKeys($artist) as $key) {
                if ($this->navidrome->hasArtistInLibrary($key)) {
                    $artist = $key;
                    break;
                }
            }
        }

        if (!$this->navidrome->hasArtistInLibrary($artist)) {
            // Distance relative to the name length: one edit per 5 characters,
            // capped by artistMaxDistance. Short names no longer suggest
            // unrelated artists (« Colas » → « Coins », « Rocé » → « Rone »).
            $maxDistance = min(
                $this->artistMaxDistance,
                intdiv(mb_strlen(NavidromeStringNormalizer::normalize($artist)), 5),
            );
            $artistSuggestions = $maxDistance > 0
                ? $this->navidrome->findNearestArtistNames($artist, $maxDistance)
                : [];
            if ($artistSuggestions !== []) {
                return [
                    'reason' => self::REASON_ARTIST_NEAR_MATCH,
                    'artist_suggestions' => $artistSuggestions,
                ];
            }

            return ['reason' => self::REASON_ARTIST_UNKNOWN];
        }

        // Exact title for this artist (artist OR album_artist) BEFORE the
        // fuzzy-title step. A hit here means the track is actually owned
        // — the cascade just missed it (typically a stale negative cache
        // entry, or a tagging asymmetry the cascade is too strict to
        // bridge). Most actionable category : the user just needs to
        // rematch (cache now busted) or, worst case, create a direct alias.
        $mfid = $this->navidrome->findMediaFileByArtistOrAlbumArtistAndTitle($artist, $title)
            ?? $this->navidrome->findMediaFileByBareForms($artist, $title);
        if ($mfid !== null) {
            return [
                'reason' => self::REASON_MATCHER_GAP,
                'target_media_file_id' => $mfid,
            ];
        }

        // Edit distance first, then titles that start with / contain the
        // scrobbled one (« Taj Mahal » → « Taj Mahal feat. The Alchemist »,
        // issue #280), which the distance alone always misses.
        $titleSuggestions = $this->navidrome->findNearestTitlesForArtist(
            $artist,
            $title,
            $this->titleMaxDistance,
        );
        foreach ($this->navidrome->findTitlesExtendingForArtist($artist, $title) as $suggestion) {
            if (count($titleSuggestions) >= 3) {
                break;
            }
            if (!in_array($suggestion['title'], array_column($titleSuggestions, 'title'), true)) {
                $titleSuggestions[] = $suggestion;
            }
        }
        if ($titleSuggestions !== []) {
            return [
                'reason' => self::REASON_TITLE_NEAR_MATCH,
                'title_suggestions' => $titleSuggestions,
            ];
        }

        return ['reason' => self::REASON_TRACK_MISSING];
    }
}
