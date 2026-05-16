<?php

namespace App\LastFm;

use App\Repository\SettingRepository;

/**
 * Single source of truth for resolving the (dateMin, dateMax) window used by
 * `app:lastfm:fetch` (CLI) and `FetchLastFmMessageHandler` (web UI). Both
 * paths previously duplicated the smart-date logic and drifted out of sync —
 * the web button defaulted to "full history" on a fresh install while the
 * CLI defaulted to 48h. This service is the canonical implementation.
 *
 * Resolution order:
 *  1. explicit --date-min → that wins.
 *  2. setting[lastfm_last_fetch_{user}] present → `last_fetch - OVERLAP_HOURS`.
 *  3. nothing recorded yet → `now - DEFAULT_WINDOW_HOURS` (no full bootstrap
 *     by accident on a cron tick).
 */
final class FetchWindowResolver
{
    public const SETTING_KEY_PREFIX = 'lastfm_last_fetch_';
    public const OVERLAP_HOURS = 1;
    public const DEFAULT_WINDOW_HOURS = 48;

    public function __construct(private readonly SettingRepository $settings)
    {
    }

    /**
     * @return array{dateMin: \DateTimeImmutable, dateMax: ?\DateTimeImmutable, source: 'explicit'|'smart'|'default'}
     */
    public function resolve(string $user, ?string $explicitDateMin, ?string $explicitDateMax): array
    {
        $dateMax = $explicitDateMax !== null ? new \DateTimeImmutable($explicitDateMax) : null;

        if ($explicitDateMin !== null) {
            return [
                'dateMin' => new \DateTimeImmutable($explicitDateMin),
                'dateMax' => $dateMax,
                'source' => 'explicit',
            ];
        }

        $lastFetch = $this->settings->get(self::SETTING_KEY_PREFIX . $user);
        if ($lastFetch !== '') {
            return [
                'dateMin' => (new \DateTimeImmutable($lastFetch))
                    ->modify(sprintf('-%d hours', self::OVERLAP_HOURS)),
                'dateMax' => $dateMax,
                'source' => 'smart',
            ];
        }

        return [
            'dateMin' => (new \DateTimeImmutable())
                ->modify(sprintf('-%d hours', self::DEFAULT_WINDOW_HOURS)),
            'dateMax' => $dateMax,
            'source' => 'default',
        ];
    }

    /**
     * Record a successful fetch so the next smart-date run knows where to
     * resume. Callers should only invoke this when source !== 'explicit' and
     * the run actually pulled scrobbles (no point shifting the cursor on a
     * dry-run or empty window).
     */
    public function markFetchedAt(string $user, \DateTimeImmutable $at): void
    {
        $this->settings->set(self::SETTING_KEY_PREFIX . $user, $at->format('Y-m-d H:i:s'));
    }
}
