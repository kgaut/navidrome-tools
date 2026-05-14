<?php

namespace App\MessageHandler;

use App\Entity\RunHistory;
use App\Message\SyncStrawberryMessage;
use App\Strawberry\StrawberrySyncReport;
use App\Strawberry\StrawberrySyncService;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SyncStrawberryMessageHandler
{
    public function __construct(
        private readonly StrawberrySyncService $syncService,
        private readonly RunHistoryRecorder $recorder,
    ) {
    }

    public function __invoke(SyncStrawberryMessage $message): void
    {
        $this->recorder->record(
            type: RunHistory::TYPE_STRAWBERRY_SYNC,
            reference: 'scrobbles',
            label: 'Strawberry sync' . ($message->dryRun ? ' [dry-run]' : '') . ($message->retryUnmatched ? ' +retry' : ''),
            action: fn (RunHistory $entry) => $this->syncService->process(
                limit: $message->limit,
                dryRun: $message->dryRun,
                retryUnmatched: $message->retryUnmatched,
                run: $entry,
            ),
            extractMetrics: static fn (StrawberrySyncReport $r) => [
                'prepared' => $r->prepared,
                'considered' => $r->considered,
                'matched' => $r->matched,
                'unmatched' => $r->unmatched,
                'dry_run' => $r->dryRun,
            ],
        );
    }
}
