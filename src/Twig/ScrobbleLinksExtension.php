<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Outbound links surfaced on the scrobble history page (and reusable
 * elsewhere): canonical Last.fm track page (built from artist + title with
 * the same path scheme Last.fm uses), MusicBrainz entity URLs from MBIDs
 * already stored on `scrobbles`, and Navidrome's web UI track URL for the
 * scrobble whose match landed on a known `media_file.id`.
 *
 * All helpers return `null` when the input is empty / missing — the
 * template `{% if %}` guards then hide the icon rather than rendering a
 * broken stub.
 */
class ScrobbleLinksExtension extends AbstractExtension
{
    private const LASTFM_BASE = 'https://www.last.fm/music';
    private const MUSICBRAINZ_BASE = 'https://musicbrainz.org';
    private const ALLOWED_MB_TYPES = ['artist', 'release', 'release-group', 'recording'];

    private readonly string $navidromeUrl;

    public function __construct(?string $navidromeUrl = '')
    {
        $this->navidromeUrl = (string) $navidromeUrl;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('lastfm_track_url', $this->lastFmTrackUrl(...)),
            new TwigFunction('lastfm_artist_url', $this->lastFmArtistUrl(...)),
            new TwigFunction('musicbrainz_url', $this->musicbrainzUrl(...)),
            new TwigFunction('navidrome_track_url', $this->navidromeTrackUrl(...)),
        ];
    }

    public function lastFmTrackUrl(string $artist, string $title): ?string
    {
        $artist = trim($artist);
        $title = trim($title);
        if ($artist === '' || $title === '') {
            return null;
        }
        // Last.fm uses `+` as a space marker in its canonical URLs and
        // double-encodes the rest. rawurlencode keeps the rest safe while we
        // swap spaces back to `+` for visual parity with what the user sees
        // when copying a link from the Last.fm UI.
        return sprintf(
            '%s/%s/_/%s',
            self::LASTFM_BASE,
            str_replace('%20', '+', rawurlencode($artist)),
            str_replace('%20', '+', rawurlencode($title)),
        );
    }

    public function lastFmArtistUrl(string $artist): ?string
    {
        $artist = trim($artist);
        if ($artist === '') {
            return null;
        }

        return self::LASTFM_BASE . '/' . str_replace('%20', '+', rawurlencode($artist));
    }

    public function musicbrainzUrl(string $type, ?string $mbid): ?string
    {
        if ($mbid === null || trim($mbid) === '' || !in_array($type, self::ALLOWED_MB_TYPES, true)) {
            return null;
        }

        return sprintf('%s/%s/%s', self::MUSICBRAINZ_BASE, $type, trim($mbid));
    }

    public function navidromeTrackUrl(?string $mediaFileId): ?string
    {
        $base = trim($this->navidromeUrl);
        if ($base === '' || $mediaFileId === null || trim($mediaFileId) === '') {
            return null;
        }

        return rtrim($base, '/') . '/app/#/song/' . rawurlencode(trim($mediaFileId)) . '/show';
    }
}
