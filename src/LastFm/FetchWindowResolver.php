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
        $dateMax = $explicitDateMax !== null ? self::parseDateMax($explicitDateMax) : null;

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

    /**
     * Parse the explicit date-max. The CLI doc advertises `YYYY-MM-DD`, and
     * the web form sends an HTML `<input type="date">` value in the same
     * shape — both naturally mean « inclure tout ce jour-là ». PHP's
     * default new DateTimeImmutable('YYYY-MM-DD') would set 00:00:00, i.e.
     * the START of the day; sent as `to=` to Last.fm that excludes every
     * scrobble of the day. Promote bare dates to the start of the NEXT day
     * so today's scrobbles aren't dropped.
     *
     * Inputs that already carry a time component are kept verbatim — power
     * users may want precise windows for backfills.
     */
    private static function parseDateMax(string $value): \DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return (new \DateTimeImmutable($value))->modify('+1 day');
        }

        return new \DateTimeImmutable($value);
    }
}
