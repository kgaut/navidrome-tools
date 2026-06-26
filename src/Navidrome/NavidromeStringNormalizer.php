<?php

namespace App\Navidrome;

/**
 * Pure string helpers used by the matching cascade (Last.fm scrobble →
 * Navidrome media_file) and by every code path that normalizes artist /
 * title before comparison. Extracted from {@see NavidromeRepository} so
 * the SQL repo stops being a god object — these are stateless functions
 * that don't need a DB connection.
 *
 * `normalize()` is also exposed to SQLite as the UDF `np_normalize()` by
 * {@see NavidromeRepository::connection()} so the same transformation is
 * applied server-side on indexed columns. Callers that already write
 * `NavidromeRepository::normalize(...)` continue to work via a thin
 * delegation kept on the repo for backward compatibility.
 */
final class NavidromeStringNormalizer
{
    /**
     * Canonical comparison form for an artist / album / title :
     *  - lowercase, trim
     *  - NFKD decomposition + drop combining marks (accents)
     *  - drop punctuation / symbols (keep letters, digits, whitespace)
     *  - collapse internal whitespace to a single space.
     *
     * `Beyoncé` → `beyonce`, `AC/DC` → `acdc`, `Sigur Rós` → `sigur ros`,
     * `Get Lucky (feat. Pharrell Williams)` → `get lucky feat pharrell williams`.
     *
     * Falls back to the lowercased input when NFKD fails (e.g. input
     * isn't valid UTF-8).
     */
    public static function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $decomposed = \Normalizer::normalize($s, \Normalizer::FORM_KD);
        if ($decomposed === false) {
            return $s;
        }
        $stripped = (string) preg_replace('/\p{Mn}+/u', '', $decomposed);
        // Drop punctuation/symbols (keep letters, digits, whitespace).
        $cleaned = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', '', $stripped);

        return trim((string) preg_replace('/\s+/u', ' ', $cleaned));
    }

    /**
     * Drop `feat. …` / `ft. …` / `featuring …` from a raw artist string,
     * both the parenthesized form and the trailing « X feat. Y » form
     * (which we never strip from a title — too risky on legit lyrics).
     * Returns the input unchanged when no marker is present. Works on
     * the raw input because {@see self::normalize()} would strip parens
     * and dots, defeating the patterns below.
     */
    public static function stripFeaturedArtists(string $artist): string
    {
        // 1. Strip parenthesized suffix: "(feat. …)" / "(ft. …)" / "(featuring …)".
        $stripped = preg_replace('/\s*\((?:feat\.?|ft\.?|featuring)\s+[^)]*\)\s*/iu', '', $artist) ?? $artist;
        // 2. Strip trailing form: " feat. …" / " ft. …" / " featuring …" until end.
        $stripped = preg_replace('/\s+(?:feat\.?|ft\.?|featuring)\s+.*$/iu', '', $stripped) ?? $stripped;

        return trim($stripped);
    }

    /**
     * Drop a trailing version-marker suffix (Radio Edit, Remastered 2011,
     * Live, Acoustic, Deluxe Edition…) from a raw title. Handles
     * parenthesized, bracketed, and dash-separated forms (ASCII -, en
     * dash, em dash). Live/acoustic/etc. is only stripped when *delimited*
     * — « Live and Let Die » remains intact. Remix is intentionally NOT
     * stripped (DJ remixes are usually distinct recordings).
     */
    public static function stripVersionMarkers(string $title): string
    {
        $markers = '(?:'
            . 'remastered \d{4}|remaster \d{4}|\d{4} remastered|\d{4} remaster'
            . '|radio edit|radio mix|radio version'
            . '|album version|album mix|album edit'
            . '|single version|single edit|single mix'
            . '|extended version|extended mix|extended edit'
            . '|mono version|stereo version'
            . '|remastered|remaster'
            . '|live(?:\s[^)\]]+)?'
            . '|acoustic(?:\s+(?:version|mix))?'
            . '|instrumental(?:\s+version)?'
            . '|demo(?:\s+version)?'
            . '|deluxe(?:\s+(?:edition|version))?'
            . ')';

        $stripped = preg_replace('/\s*\(' . $markers . '\)\s*$/iu', '', $title) ?? $title;
        $stripped = preg_replace('/\s*\[' . $markers . '\]\s*$/iu', '', $stripped) ?? $stripped;
        $stripped = preg_replace('/\s+[\-\x{2013}\x{2014}]\s+' . $markers . '\s*$/iu', '', $stripped) ?? $stripped;

        return trim($stripped);
    }

    /**
     * Drop a trailing edition / reissue decoration from a raw album title
     * — « (Deluxe Edition) », « [Remastered] », « (Bonus Track Edition) »,
     * « (2011 Remaster) », « - Anniversary Edition », « (Explicit) »…
     * Symmetric to {@see stripVersionMarkers()} but tuned for album-level
     * suffixes, so a scrobble album « Random Access Memories » still
     * matches a library album « Random Access Memories (Deluxe Edition) »
     * after both are stripped. Only the *delimited* trailing form is
     * touched — a bare « Deluxe » album name is left intact.
     */
    public static function stripAlbumDecorations(string $album): string
    {
        $markers = '(?:'
            . 'deluxe(?:\s+(?:edition|version))?'
            . '|expanded(?:\s+edition)?'
            . '|special(?:\s+edition)?'
            . '|anniversary(?:\s+edition)?'
            . '|collector\'?s?(?:\s+edition)?'
            . '|limited(?:\s+edition)?'
            . '|bonus(?:\s+track)?s?(?:\s+(?:edition|version))?'
            . '|remastered \d{4}|remaster \d{4}|\d{4} remastered|\d{4} remaster'
            . '|remastered|remaster'
            . '|reissue'
            . '|explicit(?:\s+(?:version|content))?'
            . '|clean(?:\s+version)?'
            . ')';

        $stripped = preg_replace('/\s*\(' . $markers . '\)\s*$/iu', '', $album) ?? $album;
        $stripped = preg_replace('/\s*\[' . $markers . '\]\s*$/iu', '', $stripped) ?? $stripped;
        $stripped = preg_replace('/\s+[\-\x{2013}\x{2014}]\s+' . $markers . '\s*$/iu', '', $stripped) ?? $stripped;

        return trim($stripped);
    }

    /**
     * Drop a parenthesized/bracketed featuring-with suffix from a title.
     * Only the delimited form — never « X feat. Y » without parens, too
     * risky on real titles.
     */
    public static function stripFeaturingFromTitle(string $title): string
    {
        $pattern = '(?:feat\.?|ft\.?|featuring|with)\s+[^)\]]+';
        $stripped = preg_replace('/\s*\(' . $pattern . '\)\s*$/iu', '', $title) ?? $title;
        $stripped = preg_replace('/\s*\[' . $pattern . '\]\s*$/iu', '', $stripped) ?? $stripped;

        return trim($stripped);
    }

    /**
     * Drop a leading track-number prefix like « 01 - », « 02_ », « 12- »,
     * « 100. ». Requires a delimiter and a non-blank character behind so
     * standalone numeric titles (« 1979 », « 5/4 ») are not eaten.
     */
    public static function stripTrackNumberPrefix(string $title): string
    {
        return trim(preg_replace('/^\d{1,3}[_\-.\s]+(?=\S)/u', '', $title) ?? $title);
    }

    /**
     * Drop a trailing OPEN parenthesis block when its content starts with
     * a known marker keyword — Last.fm truncates titles around 64 chars
     * and leaves unbalanced parens. Abstains if the title already contains
     * a closed `(...)` group.
     */
    public static function stripTruncatedParen(string $title): string
    {
        if (preg_match('/\([^)]*\)/u', $title)) {
            return $title;
        }
        $markers = '(?:feat\.?|ft\.?|featuring|with|live|acoustic|remastered|remaster|deluxe|extended|radio|album|single|mono|stereo|instrumental|demo)';

        return trim(preg_replace('/\s*\(' . $markers . '[^)]*$/iu', '', $title) ?? $title);
    }

    /**
     * Drop trailing co-artists separated by `,`, ` - `, `&`, ` and `, ` et `,
     * ` vs `, ` vs. `, ` x ` to keep only the lead artist
     * (« Médine & Rounhaa » → « Médine », « Diplo x M.I.A. » → « Diplo »).
     * The ` vs `/` x ` separators cover EDM / hip-hop collab credits.
     * Surrounding whitespace is required on the word separators so a name
     * like « Malcolm X » or « Charli XCX » is never split. Last-resort
     * fallback when the regular cascade fails.
     */
    public static function stripLeadArtist(string $artist): string
    {
        if (preg_match('/^(.*?)(?:\s*,\s*|\s+-\s+|\s*&\s*|\s+(?:and|et|vs\.?|x)\s+)/iu', $artist, $m)) {
            $lead = trim($m[1]);
            if ($lead !== '' && $lead !== $artist) {
                return $lead;
            }
        }

        return $artist;
    }

    /**
     * True when the title carries a `(feat. …)` / `[ft. …]` / `(with …)`
     * marker (closed or truncated open-paren). Gates the « move the
     * featuring into the artist column » heuristic in the matcher.
     */
    public static function titleHasFeaturingMarker(string $title): bool
    {
        if (preg_match('/\((?:feat\.?|ft\.?|featuring|with)\s+[^)]+\)/iu', $title)) {
            return true;
        }
        if (preg_match('/\[(?:feat\.?|ft\.?|featuring|with)\s+[^\]]+\]/iu', $title)) {
            return true;
        }

        return !preg_match('/\([^)]*\)/u', $title)
            && (bool) preg_match('/\((?:feat\.?|ft\.?|featuring|with)\s+/iu', $title);
    }
}
