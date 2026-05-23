<?php

namespace App\MessageHandler;

use App\Entity\RunHistory;
use App\LastFm\FetchReport;
use App\LastFm\FetchWindowResolver;
use App\LastFm\LastFmFetcher;
use App\Message\FetchLastFmMessage;
use App\Message\SyncLastFmLovedMessage;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
class FetchLastFmMessageHandler
{
    public function __construct(
        private readonly LastFmFetcher $fetcher,
        private readonly RunHistoryRecorder $recorder,
        private readonly FetchWindowResolver $windowResolver,
        private readonly MessageBusInterface $bus,
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

        // Chain a loved-sync so /lastfm/stats heart counts stay honest
        // even when older scrobbles missed the live `loved` flag. Skip on
        // dry-run (would still hit the API for nothing).
        if (!$message->dryRun) {
            $this->bus->dispatch(new SyncLastFmLovedMessage(
                user: $message->user,
                apiKey: $message->apiKey,
                dryRun: false,
            ));
        }
    }
}
