<?php

namespace App\Scrobble;

use App\Entity\RunHistory;
use App\LastFm\FetchReport;
use App\Service\RunHistoryRecorder;

/**
 * One fetch of the active source (issue #310), shared by
 * `app:scrobbles:fetch` and the web import page: resolves the window,
 * records the run, then stores the last-fetch mark.
 */
class ScrobbleFetchRunner
{
    public function __construct(
        private readonly ActiveScrobbleSource $activeSource,
        private readonly ScrobbleFetcher $fetcher,
        private readonly SourceFetchWindowResolver $windowResolver,
        private readonly RunHistoryRecorder $recorder,
    ) {
    }

    /**
     * @throws \InvalidArgumentException on an unknown or unconfigured source
     */
    public function source(): ScrobbleSourceInterface
    {
        $source = $this->activeSource->get();
        if (!$source->isConfigured()) {
            throw new \InvalidArgumentException(sprintf('Source « %s » non configurée (utilisateur ou clé manquants).', $source->name()));
        }

        return $source;
    }

    /**
     * @param callable(array{dateMin: \DateTimeImmutable, dateMax: ?\DateTimeImmutable, source: string}): void|null $onWindow
     * @param callable(int, int, int): void|null                                                                   $progress
     */
    public function run(
        ?string $dateMin,
        ?string $dateMax,
        ?int $maxScrobbles,
        bool $dryRun,
        ?callable $onWindow = null,
        ?callable $progress = null,
    ): FetchReport {
        $source = $this->source();
        $window = $this->windowResolver->resolve($source, $dateMin, $dateMax);
        if ($onWindow !== null) {
            $onWindow($window);
        }
        $now = new \DateTimeImmutable();

        $report = $this->recorder->record(
            type: $source->name() === ListenBrainzScrobbleSource::NAME ? RunHistory::TYPE_LISTENBRAINZ_FETCH : RunHistory::TYPE_LASTFM_FETCH,
            reference: $source->user(),
            label: sprintf('%s fetch %s%s', $source->name(), $source->user(), $dryRun ? ' [dry-run]' : ''),
            action: fn () => $this->fetcher->fetch($source, $window['dateMin'], $window['dateMax'], $maxScrobbles, $dryRun, $progress),
            extractMetrics: static fn (FetchReport $r) => [
                'source' => $source->name(),
                'fetched' => $r->fetched,
                'inserted' => $r->inserted,
                'duplicates' => $r->duplicates,
                'window' => $window['source'],
                'dry_run' => $dryRun,
            ],
        );

        // Record the fetch only for computed windows. A raccord that found
        // nothing new still records it: the next run then uses smart date.
        if ($window['source'] !== 'explicit' && !$dryRun && ($report->fetched > 0 || $window['source'] === 'raccord')) {
            $this->windowResolver->markFetchedAt($source, $now);
        }

        return $report;
    }

    public function lastFetchAt(): ?\DateTimeImmutable
    {
        try {
            return $this->windowResolver->lastFetchAt($this->activeSource->get());
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
