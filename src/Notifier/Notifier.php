<?php

namespace App\Notifier;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Fan-out notifier : iterates over every driver tagged
 * `app.notifier_driver`, picks those listed in NOTIFY_DRIVERS, applies
 * the NOTIFY_ON filter, and best-effort dispatches. A failing driver
 * is logged but never blocks the others or the calling job.
 */
class Notifier
{
    public const ON_ALL = 'all';
    public const ON_ERROR = 'error';

    /** @var list<string> */
    private readonly array $enabledDriverNames;

    /** @var list<NotifierDriverInterface> */
    private readonly array $drivers;

    /** @param iterable<NotifierDriverInterface> $drivers */
    public function __construct(
        iterable $drivers,
        string $enabledDriversCsv,
        private readonly string $notifyOn = self::ON_ERROR,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->drivers = is_array($drivers) ? array_values($drivers) : iterator_to_array($drivers, false);
        $this->enabledDriverNames = self::parseDriverCsv($enabledDriversCsv);
    }

    /**
     * @return list<string>
     */
    private static function parseDriverCsv(string $csv): array
    {
        $names = [];
        foreach (explode(',', $csv) as $raw) {
            $name = strtolower(trim($raw));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    public function isEnabled(): bool
    {
        return $this->enabledDriverNames !== [];
    }

    public function notify(Notification $notification): void
    {
        if ($this->enabledDriverNames === []) {
            return;
        }
        if (!$notification->isError() && $this->notifyOn !== self::ON_ALL) {
            return;
        }

        foreach ($this->drivers as $driver) {
            if (!in_array($driver->getName(), $this->enabledDriverNames, true)) {
                continue;
            }
            if (!$driver->isConfigured()) {
                $this->logger->warning('Notifier driver "{driver}" is listed in NOTIFY_DRIVERS but not configured — skipping.', [
                    'driver' => $driver->getName(),
                ]);
                continue;
            }
            try {
                $driver->send($notification);
            } catch (\Throwable $e) {
                $this->logger->error('Notifier driver "{driver}" failed: {message}', [
                    'driver' => $driver->getName(),
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }
    }
}
