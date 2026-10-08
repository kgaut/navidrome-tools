<?php

namespace App;

use App\Log\RedactingLogger;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    /**
     * Symfony's HTTP client logs every request URL (query string included)
     * through `logger`: hand it a decorator that masks credentials instead
     * (Subsonic `t`/`s`, Last.fm `api_key`…, issue #265).
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('http_client.transport') || !$container->has('logger')) {
            return;
        }
        $container->setDefinition(
            'app.http_client.redacting_logger',
            new Definition(RedactingLogger::class, [new Reference('logger')]),
        );
        $container->getDefinition('http_client.transport')
            ->removeMethodCall('setLogger')
            ->addMethodCall('setLogger', [
                new Reference('app.http_client.redacting_logger', ContainerInterface::IGNORE_ON_INVALID_REFERENCE),
            ]);
    }

    public function boot(): void
    {
        // Apply APP_TIMEZONE before any controller / command runs. We set it
        // BOTH on PHP (date_default_timezone_set) AND on the C runtime
        // (putenv TZ) so SQLite's `'localtime'` modifier — used by the daily
        // stats API to bucket plays in local time — resolves to the same zone
        // regardless of the container's own TZ. SQLite reads TZ, not PHP's tz.
        $tz = $_SERVER['APP_TIMEZONE'] ?? $_ENV['APP_TIMEZONE'] ?? 'UTC';
        if (is_string($tz) && $tz !== '') {
            try {
                new \DateTimeZone($tz);
                date_default_timezone_set($tz);
                putenv('TZ=' . $tz);
            } catch (\Exception) {
                date_default_timezone_set('UTC');
                putenv('TZ=UTC');
            }
        }

        parent::boot();
    }

    public function getCacheDir(): string
    {
        // Allow Docker to point the compiled-container cache outside the
        // persistent /app/var volume — avoids cache-race between web and
        // worker containers sharing the same named volume.
        $override = $_SERVER['APP_CACHE_DIR'] ?? $_ENV['APP_CACHE_DIR'] ?? null;
        if (is_string($override) && $override !== '') {
            return rtrim($override, '/') . '/' . $this->environment;
        }

        return parent::getCacheDir();
    }
}
