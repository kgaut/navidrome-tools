<?php

namespace App\MessageHandler;

use App\Entity\RunHistory;
use App\LastFm\FetchReport;
use App\LastFm\FetchWindowResolver;
use App\LastFm\LastFmFetcher;
use App\Message\FetchLastFmMessage;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class FetchLastFmMessageHandler
{
    public function __construct(
        private readonly LastFmFetcher $fetcher,
        private readonly RunHistoryRecorder $recorder,
        private readonly FetchWindowResolver $windowResolver,
    ) {
    }

    public function __invoke(FetchLastFmMessage $message): void
    {
        $window = $this->windowResolver->resolve($message->user, $message->dateMin, $message->dateMax);
        $smartDate = $window['source'] !== 'explicit';
        $now = new \DateTimeImmutable();

        $report = $this->recorder->record(
            type: RunHistory::TYPE_LASTFM_FETCH,
            reference: $message->user,
            label: sprintf('Last.fm fetch %s%s', $message->user, $message->dryRun ? ' [dry-run]' : ''),
            action: fn () => $this->fetcher->fetch(
                apiKey: $message->apiKey,
                lastFmUser: $message->user,
                dateMin: $window['dateMin'],
                dateMax: $window['dateMax'],
                maxScrobbles: $message->maxScrobbles,
                dryRun: $message->dryRun,
            ),
            extractMetrics: static fn (FetchReport $r) => [
                'fetched' => $r->fetched,
                'inserted' => $r->inserted,
                'duplicates' => $r->duplicates,
                'smart_date' => $smartDate,
                'dry_run' => $message->dryRun,
            ],
        );

        if ($smartDate && !$message->dryRun && $report->fetched > 0) {
            $this->windowResolver->markFetchedAt($message->user, $now);
        }
    }
}
