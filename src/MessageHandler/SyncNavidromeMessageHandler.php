<?php

namespace App\MessageHandler;

use App\Docker\NavidromeContainerManager;
use App\Entity\RunHistory;
use App\Message\SyncNavidromeMessage;
use App\Navidrome\NavidromeSyncReport;
use App\Navidrome\NavidromeSyncService;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class SyncNavidromeMessageHandler
{
    public function __construct(
        private readonly NavidromeSyncService $syncService,
        private readonly RunHistoryRecorder $recorder,
        private readonly NavidromeContainerManager $container,
    ) {
    }

    public function __invoke(SyncNavidromeMessage $message): void
    {
        $runProcess = fn () => $this->recorder->record(
            type: RunHistory::TYPE_NAVIDROME_SYNC,
            reference: 'scrobbles',
            label: 'Navidrome sync' . ($message->dryRun ? ' [dry-run]' : ''),
            action: fn (RunHistory $entry) => $this->syncService->process(
                limit: $message->limit,
                dryRun: $message->dryRun,
                toleranceSeconds: $message->toleranceSeconds,
                run: $entry,
            ),
            extractMetrics: static fn (NavidromeSyncReport $r) => [
                'prepared' => $r->prepared,
                'considered' => $r->considered,
                'matched' => $r->matched,
                'duplicates' => $r->duplicates,
                'unmatched' => $r->unmatched,
                'skipped' => $r->skipped,
                'api_errors' => $r->apiErrors,
                'dry_run' => $r->dryRun,
            ],
        );

        if ($message->autoStop && !$message->dryRun) {
            $this->container->runWithNavidromeStopped($runProcess);
        } else {
            $runProcess();
        }
    }
}
