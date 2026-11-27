<?php

namespace App\Scrobble;

use App\LastFm\FetchWindowResolver;
use App\Repository\ScrobbleRepository;
use App\Repository\SettingRepository;

/**
 * Fetch window for any listen source (issue #310). Last.fm keeps its own
 * resolver and settings untouched; other sources resolve, in order:
 *
 *  1. explicit --date-min → that wins;
 *  2. setting[{source}_last_fetch_{user}] → `last_fetch - OVERLAP_HOURS`;
 *  3. **raccord**: first fetch from this source on a non-empty table → one
 *     second after the most recent listen already stored, whatever its source
 *     (ADR 0310-scrobbles-listenbrainz-source-unique-raccord-par-horodatage);
 *  4. empty table → `now - DEFAULT_WINDOW_HOURS`, as Last.fm.
 */
class SourceFetchWindowResolver
{
    public function __construct(
        private readonly FetchWindowResolver $lastFm,
        private readonly SettingRepository $settings,
        private readonly ScrobbleRepository $scrobbles,
    ) {
    }

    /**
     * @return array{dateMin: \DateTimeImmutable, dateMax: ?\DateTimeImmutable, source: 'explicit'|'smart'|'default'|'raccord'}
     */
    public function resolve(ScrobbleSourceInterface $source, ?string $explicitDateMin, ?string $explicitDateMax): array
    {
        if ($source->name() === LastFmScrobbleSource::NAME || $explicitDateMin !== null) {
            return $this->lastFm->resolve($source->user(), $explicitDateMin, $explicitDateMax);
        }

        $dateMax = $explicitDateMax !== null ? FetchWindowResolver::parseDateMax($explicitDateMax) : null;

        $lastFetch = $this->settings->get(self::settingKey($source));
        if ($lastFetch !== '') {
            return [
                'dateMin' => (new \DateTimeImmutable($lastFetch))
                    ->modify(sprintf('-%d hours', FetchWindowResolver::OVERLAP_HOURS)),
                'dateMax' => $dateMax,
                'source' => 'smart',
            ];
        }

        $lastPlayed = $this->scrobbles->getLastPlayedAtOverall();
        if ($lastPlayed !== null) {
            return [
                'dateMin' => $lastPlayed->modify('+1 second'),
                'dateMax' => $dateMax,
                'source' => 'raccord',
            ];
        }

        return [
            'dateMin' => (new \DateTimeImmutable())
                ->modify(sprintf('-%d hours', FetchWindowResolver::DEFAULT_WINDOW_HOURS)),
            'dateMax' => $dateMax,
            'source' => 'default',
        ];
    }

    public function markFetchedAt(ScrobbleSourceInterface $source, \DateTimeImmutable $at): void
    {
        if ($source->name() === LastFmScrobbleSource::NAME) {
            $this->lastFm->markFetchedAt($source->user(), $at);
            return;
        }
        $this->settings->set(self::settingKey($source), $at->format('Y-m-d H:i:s'));
    }

    private static function settingKey(ScrobbleSourceInterface $source): string
    {
        return $source->name() . '_last_fetch_' . $source->user();
    }
}
