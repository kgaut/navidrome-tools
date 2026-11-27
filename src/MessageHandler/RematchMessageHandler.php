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
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class RematchMessageHandler
{
    public function __construct(
        private readonly ScrobbleSyncRepository $syncRepo,
        private readonly NavidromeSyncService $navidromeSync,
        private readonly RunHistoryRecorder $recorder,
        private readonly NavidromeContainerManager $container,
        private readonly LastFmMatchCacheRepository $matchCache,
    ) {
    }

    public function __invoke(RematchMessage $message): void
    {
        // Navidrome is the only target since #313; a message queued before
        // the upgrade for another target is dropped.
        if ($message->target !== ScrobbleSync::TARGET_NAVIDROME) {
            return;
        }

        $runProcess = fn () => $this->recorder->record(
            type: RunHistory::TYPE_NAVIDROME_REMATCH,
            reference: 'unmatched',
            label: sprintf('%s rematch%s', $message->target, $message->dryRun ? ' [dry-run]' : ''),
            action: function (RunHistory $entry) use ($message): NavidromeSyncReport {
                // Bust the LastFm match cache for couples we're about to
                // retry. Otherwise the matcher hits its own fresh negative
                // entry (TTL 30j by default) for the very couples the user
                // is asking to rematch, and the run is a no-op. Positives
                // are preserved — those are still valid resolutions.
                $this->matchCache->purgeUnmatchedNegatives($message->target);
                $this->syncRepo->resetUnmatchedToPending($message->target);

                return $this->navidromeSync->process(limit: $message->limit, dryRun: $message->dryRun, run: $entry);
            },
            extractMetrics: static fn (NavidromeSyncReport $r): array => [
                'considered' => $r->considered,
                'matched' => $r->matched,
                'unmatched' => $r->unmatched,
            ] + ($r->apiErrors > 0 ? ['api_errors' => $r->apiErrors] : []),
        );

        if ($message->autoStop && !$message->dryRun) {
            $this->container->runWithNavidromeStopped($runProcess);
        } else {
            $runProcess();
        }
    }
}
