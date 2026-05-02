<?php

namespace App\Service;

/**
 * On-disk cache for cover art binaries fetched from Navidrome via the
 * Subsonic API. Pure logic — no HTTP — so it's easy to test.
 *
 * Layout: <cacheRoot>/<type>/<id>-<size>.jpg.
 *  - $type ∈ {album, artist, song} — anything else throws.
 *  - $id is constrained to [A-Za-z0-9_-]+ — the route already restricts
 *    it the same way, but we re-check here to keep this class safe in
 *    isolation against path traversal.
 */
class CoverArtCache
{
    private const ALLOWED_TYPES = ['album', 'artist', 'song'];

    public function __construct(
        private readonly string $cacheRoot,
    ) {
    }

    public function pathFor(string $type, string $id, int $size): string
    {
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('Cover type "%s" is not supported.', $type));
        }
        if ($id === '' || preg_match('/^[A-Za-z0-9_-]+$/', $id) !== 1) {
            throw new \InvalidArgumentException(sprintf('Cover id "%s" is not safe to use as a filename.', $id));
        }
        if ($size < 0) {
            throw new \InvalidArgumentException('Cover size must be >= 0.');
        }

        return sprintf('%s/%s/%s-%d.jpg', rtrim($this->cacheRoot, '/'), $type, $id, $size);
    }

    public function get(string $type, string $id, int $size): ?string
    {
        $path = $this->pathFor($type, $id, $size);

        return file_exists($path) ? $path : null;
    }

    public function store(string $type, string $id, int $size, string $bytes): string
    {
        $path = $this->pathFor($type, $id, $size);
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Failed to create cover cache directory "%s".', $dir));
        }
        if (file_put_contents($path, $bytes) === false) {
            throw new \RuntimeException(sprintf('Failed to write cover cache file "%s".', $path));
        }

        return $path;
    }
}
