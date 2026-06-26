<?php

namespace App\MessageHandler;

use App\Docker\NavidromeContainerManager;
use App\Entity\RunHistory;
use App\Entity\ScrobbleSync;
use App\Message\RematchMessage;
use App\Navidrome\NavidromeSyncReport;
use App\Navidrome\NavidromeSyncService;
use App\Repository\LastFmMatchCacheRepository;
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
        private readonly LastFmMatchCacheRepository $matchCache,
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
                // Bust the LastFm match cache for couples we're about to
                // retry. Otherwise the matcher hits its own fresh negative
                // entry (TTL 30j by default) for the very couples the user
                // is asking to rematch, and the run is a no-op. Positives
                // are preserved — those are still valid resolutions.
                if ($message->target === ScrobbleSync::TARGET_NAVIDROME) {
                    $this->matchCache->purgeUnmatchedNegatives($message->target);
                }
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
