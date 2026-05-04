<?php

namespace App\MessageHandler;

use App\Docker\NavidromeContainerManager;
use App\Entity\RunHistory;
use App\LastFm\RematchReport;
use App\Message\RunLastFmRematchMessage;
use App\Repository\LastFmImportTrackRepository;
use App\Repository\RunHistoryRepository;
use App\Service\LastFmRematchService;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RunLastFmRematchHandler
{
    public function __construct(
        private readonly RunHistoryRepository $repository,
        private readonly RunHistoryRecorder $recorder,
        private readonly LastFmRematchService $rematch,
        private readonly LastFmImportTrackRepository $trackRepo,
        private readonly NavidromeContainerManager $containerManager,
    ) {
    }

    public function __invoke(RunLastFmRematchMessage $msg): void
    {
        $entry = $this->repository->find($msg->runHistoryId);
        if (!$entry instanceof RunHistory) {
            return;
        }

        $total = $this->trackRepo->countUnmatched($msg->runIdFilter);
        if ($msg->limit > 0) {
            $total = min($total, $msg->limit);
        }

        $this->recorder->recordExisting(
            entry: $entry,
            action: fn (RunHistory $run): RematchReport => $this->containerManager->runWithNavidromeStopped(
                fn (): RematchReport => $this->rematch->rematch(
                    runId: $msg->runIdFilter,
                    limit: $msg->limit,
                    dryRun: $msg->dryRun,
                    toleranceSeconds: $msg->toleranceSeconds,
                    random: $msg->random,
                    progress: function (int $considered, int $matched, int $stillUnmatched) use ($run, $total): void {
                        $this->recorder->updateProgress(
                            $run,
                            current: $considered,
                            total: $total > 0 ? $total : null,
                            message: sprintf('%d traités · %d matchés · %d toujours non matchés', $considered, $matched, $stillUnmatched),
                        );
                    },
                ),
            ),
            extractMetrics: static fn (RematchReport $r): array => [
                'considered' => $r->considered,
                'inserted' => $r->matchedAsInserted,
                'duplicate' => $r->matchedAsDuplicate,
                'skipped' => $r->skipped,
                'still_unmatched' => $r->stillUnmatched,
                'run_id_filter' => $msg->runIdFilter,
            ],
        );
    }
}
