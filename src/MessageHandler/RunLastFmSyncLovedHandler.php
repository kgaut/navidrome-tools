<?php

namespace App\MessageHandler;

use App\Entity\RunHistory;
use App\LastFm\LovedStarredSyncService;
use App\LastFm\SyncReport;
use App\Message\RunLastFmSyncLovedMessage;
use App\Repository\RunHistoryRepository;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RunLastFmSyncLovedHandler
{
    public function __construct(
        private readonly RunHistoryRepository $repository,
        private readonly RunHistoryRecorder $recorder,
        private readonly LovedStarredSyncService $sync,
    ) {
    }

    public function __invoke(RunLastFmSyncLovedMessage $msg): void
    {
        $entry = $this->repository->find($msg->runHistoryId);
        if (!$entry instanceof RunHistory) {
            return;
        }

        $this->recorder->recordExisting(
            entry: $entry,
            action: function (RunHistory $run) use ($msg): SyncReport {
                $this->recorder->updateProgress($run, current: 0, total: null, message: 'Récupération des morceaux loved/starred…');
                return $this->sync->sync($msg->direction, $msg->dryRun);
            },
            extractMetrics: static fn (SyncReport $r): array => [
                'loved' => $r->lovedCount,
                'starred' => $r->starredCount,
                'common' => $r->commonCount,
                'starred_added' => count($r->starredAdded),
                'loved_added' => count($r->lovedAdded),
                'unmatched' => count($r->lovedUnmatched),
                'errors' => count($r->errors),
                'direction' => $msg->direction,
                'dry_run' => $msg->dryRun,
            ],
        );
    }
}
