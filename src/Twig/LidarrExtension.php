<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Builds deep links into a self-hosted Lidarr instance for quick "add this
 * artist / album to my library" actions from the scrobble history listing
 * (and any other view that surfaces unmatched scrobbles).
 *
 * Returns `null` when `LIDARR_URL` is empty — the template should `{% if %}`
 * around the result so the link disappears when Lidarr isn't configured.
 *
 * Note: Lidarr's UI doesn't expose a per-album deep link in current versions
 * (2.x). The album helper pre-fills the artist + album in the search term
 * so the Lucene-style match on MB surfaces the right artist page and the
 * user picks the album from there.
 */
class LidarrExtension extends AbstractExtension
{
    private readonly string $lidarrUrl;

    public function __construct(?string $lidarrUrl = '')
    {
        $this->lidarrUrl = (string) $lidarrUrl;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('lidarr_artist_url', $this->artistUrl(...)),
            new TwigFunction('lidarr_album_url', $this->albumUrl(...)),
        ];
    }

    public function artistUrl(string $artist): ?string
    {
        $base = $this->base();
        $artist = trim($artist);
        if ($base === null || $artist === '') {
            return null;
        }

        return $base . '/add/new?term=' . rawurlencode($artist);
    }

    public function albumUrl(string $artist, string $album): ?string
    {
        $base = $this->base();
        $artist = trim($artist);
        $album = trim($album);
        if ($base === null || $artist === '') {
            return null;
        }
        $term = $album !== '' ? $artist . ' ' . $album : $artist;

        return $base . '/add/new?term=' . rawurlencode($term);
    }

    private function base(): ?string
    {
        $url = trim($this->lidarrUrl);

        return $url === '' ? null : rtrim($url, '/');
    }
}
