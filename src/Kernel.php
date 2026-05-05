<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        // Apply APP_TIMEZONE before any controller / command runs so
        // every `new \DateTimeImmutable()` and Twig `|date` filter (with
        // its own default coming from twig.yaml) use the operator's
        // timezone instead of the container's UTC default.
        $tz = $_SERVER['APP_TIMEZONE'] ?? $_ENV['APP_TIMEZONE'] ?? 'UTC';
        if (is_string($tz) && $tz !== '') {
            try {
                new \DateTimeZone($tz);
                date_default_timezone_set($tz);
            } catch (\Exception) {
                // Invalid value — fall back to UTC silently rather than crash.
                date_default_timezone_set('UTC');
            }
        }

        parent::boot();
    }

    public function getCacheDir(): string
    {
        // Allow Docker to point the compiled-container cache outside the
        // persistent /app/var volume. Without this, two container instances
        // sharing the named volume race on `rm -rf var/cache/prod` during
        // their entrypoints, and a long-running CLI command (e.g.
        // app:lastfm:import) hits a missing lazy service file at
        // ConsoleTerminateEvent — see piège #13 in CLAUDE.md.
        $override = $_SERVER['APP_CACHE_DIR'] ?? $_ENV['APP_CACHE_DIR'] ?? null;
        if (is_string($override) && $override !== '') {
            return rtrim($override, '/') . '/' . $this->environment;
        }

        return parent::getCacheDir();
    }
}
