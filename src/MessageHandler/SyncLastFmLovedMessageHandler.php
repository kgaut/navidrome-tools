<?php

namespace App\MessageHandler;

use App\Entity\RunHistory;
use App\LastFm\LastFmFetcher;
use App\LastFm\LovedSyncReport;
use App\Message\SyncLastFmLovedMessage;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SyncLastFmLovedMessageHandler
{
    public function __construct(
        private readonly LastFmFetcher $fetcher,
        private readonly RunHistoryRecorder $recorder,
    ) {
    }

    public function __invoke(SyncLastFmLovedMessage $message): void
    {
        $this->recorder->record(
            type: RunHistory::TYPE_LASTFM_LOVED_SYNC,
            reference: $message->user,
            label: sprintf('Last.fm loved sync %s%s', $message->user, $message->dryRun ? ' [dry-run]' : ''),
            action: fn () => $this->fetcher->syncLoved($message->apiKey, $message->user, $message->dryRun),
            extractMetrics: static fn (LovedSyncReport $r) => [
                'fetched' => $r->fetched,
                'matched' => $r->matched,
                'unmatched' => $r->unmatched,
                'updated_rows' => $r->updatedRows,
                'dry_run' => $message->dryRun,
            ],
        );
    }
}
