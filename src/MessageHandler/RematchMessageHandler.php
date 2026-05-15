<?php

namespace App\MessageHandler;

use App\Docker\NavidromeContainerManager;
use App\Entity\RunHistory;
use App\Entity\ScrobbleSync;
use App\Message\RematchMessage;
use App\Navidrome\NavidromeSyncReport;
use App\Navidrome\NavidromeSyncService;
use App\Repository\ScrobbleSyncRepository;
use App\Service\RunHistoryRecorder;
use App\Strawberry\StrawberrySyncReport;
use App\Strawberry\StrawberrySyncService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class RematchMessageHandler
{
    public function __construct(
        private readonly ScrobbleSyncRepository $syncRepo,
        private readonly NavidromeSyncService $navidromeSync,
        private readonly StrawberrySyncService $strawberrySync,
        private readonly RunHistoryRecorder $recorder,
        private readonly NavidromeContainerManager $container,
    ) {
    }

    public function __invoke(RematchMessage $message): void
    {
        $type = $message->target === ScrobbleSync::TARGET_NAVIDROME
            ? RunHistory::TYPE_NAVIDROME_REMATCH
            : RunHistory::TYPE_STRAWBERRY_REMATCH;

        $runProcess = fn () => $this->recorder->record(
            type: $type,
            reference: 'unmatched',
            label: sprintf('%s rematch%s', $message->target, $message->dryRun ? ' [dry-run]' : ''),
            action: function (RunHistory $entry) use ($message): NavidromeSyncReport|StrawberrySyncReport {
                $this->syncRepo->resetUnmatchedToPending($message->target);
                if ($message->target === ScrobbleSync::TARGET_NAVIDROME) {
                    return $this->navidromeSync->process(limit: $message->limit, dryRun: $message->dryRun, run: $entry);
                }
                return $this->strawberrySync->process(limit: $message->limit, dryRun: $message->dryRun, run: $entry);
            },
            extractMetrics: static fn (NavidromeSyncReport|StrawberrySyncReport $r): array => [
                'considered' => $r->considered,
                'matched' => $r->matched,
                'unmatched' => $r->unmatched,
            ],
        );

        if ($message->target === ScrobbleSync::TARGET_NAVIDROME && $message->autoStop && !$message->dryRun) {
            $this->container->runWithNavidromeStopped($runProcess);
        } else {
            $runProcess();
        }
    }
}
