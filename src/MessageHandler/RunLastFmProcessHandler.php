<?php

namespace App\MessageHandler;

use App\Docker\NavidromeContainerManager;
use App\Entity\RunHistory;
use App\LastFm\LastFmBufferProcessor;
use App\LastFm\ProcessReport;
use App\Message\RunLastFmProcessMessage;
use App\Repository\LastFmBufferedScrobbleRepository;
use App\Repository\RunHistoryRepository;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RunLastFmProcessHandler
{
    public function __construct(
        private readonly RunHistoryRepository $repository,
        private readonly RunHistoryRecorder $recorder,
        private readonly LastFmBufferProcessor $processor,
        private readonly LastFmBufferedScrobbleRepository $bufferRepo,
        private readonly NavidromeContainerManager $containerManager,
    ) {
    }

    public function __invoke(RunLastFmProcessMessage $msg): void
    {
        $entry = $this->repository->find($msg->runHistoryId);
        if (!$entry instanceof RunHistory) {
            return;
        }

        $total = $this->bufferRepo->countAll();
        if ($msg->limit > 0) {
            $total = min($total, $msg->limit);
        }

        $this->recorder->recordExisting(
            entry: $entry,
            action: fn (RunHistory $run): ProcessReport => $this->containerManager->runWithNavidromeStopped(
                fn (): ProcessReport => $this->processor->process(
                    limit: $msg->limit,
                    dryRun: $msg->dryRun,
                    toleranceSeconds: $msg->toleranceSeconds,
                    auditRun: $run,
                    progress: function (int $considered, int $inserted, int $duplicates, int $unmatched) use ($run, $total): void {
                        $this->recorder->updateProgress(
                            $run,
                            current: $considered,
                            total: $total > 0 ? $total : null,
                            message: sprintf('%d traités · %d insérés · %d doublons · %d non matchés', $considered, $inserted, $duplicates, $unmatched),
                        );
                    },
                ),
            ),
            extractMetrics: static fn (ProcessReport $r): array => [
                'considered' => $r->considered,
                'inserted' => $r->inserted,
                'duplicates' => $r->duplicates,
                'unmatched' => $r->unmatched,
                'skipped' => $r->skipped,
                'cache_hits_positive' => $r->cacheHitsPositive,
                'cache_hits_negative' => $r->cacheHitsNegative,
                'cache_misses' => $r->cacheMisses,
            ],
        );
    }
}
