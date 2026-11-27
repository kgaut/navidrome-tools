<?php

namespace App\MessageHandler;

use App\Entity\RunHistory;
use App\LastFm\LovedSyncReport;
use App\Loves\ActiveLovesProvider;
use App\Loves\LovedFlagsSync;
use App\Message\SyncLovedFlagsMessage;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SyncLovedFlagsMessageHandler
{
    public function __construct(
        private readonly ActiveLovesProvider $providers,
        private readonly LovedFlagsSync $sync,
        private readonly RunHistoryRecorder $recorder,
    ) {
    }

    public function __invoke(SyncLovedFlagsMessage $message): void
    {
        $provider = $this->providers->get();

        $this->recorder->record(
            type: RunHistory::TYPE_LOVED_FLAGS,
            reference: $provider->user(),
            label: sprintf('Loved flags %s %s%s', $provider->name(), $provider->user(), $message->dryRun ? ' [dry-run]' : ''),
            action: fn () => $this->sync->sync($provider, $message->dryRun),
            extractMetrics: static fn (LovedSyncReport $r) => [
                'source' => $provider->name(),
                'fetched' => $r->fetched,
                'matched' => $r->matched,
                'unmatched' => $r->unmatched,
                'updated_rows' => $r->updatedRows,
                'dry_run' => $message->dryRun,
            ],
        );
    }
}
