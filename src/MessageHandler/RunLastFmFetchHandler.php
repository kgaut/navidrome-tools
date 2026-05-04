<?php

namespace App\MessageHandler;

use App\Entity\RunHistory;
use App\LastFm\FetchReport;
use App\LastFm\LastFmFetcher;
use App\Message\RunLastFmFetchMessage;
use App\Repository\RunHistoryRepository;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RunLastFmFetchHandler
{
    public function __construct(
        private readonly RunHistoryRepository $repository,
        private readonly RunHistoryRecorder $recorder,
        private readonly LastFmFetcher $fetcher,
    ) {
    }

    public function __invoke(RunLastFmFetchMessage $msg): void
    {
        $entry = $this->repository->find($msg->runHistoryId);
        if (!$entry instanceof RunHistory) {
            return;
        }

        $dateMin = $msg->dateMin !== null ? new \DateTimeImmutable($msg->dateMin) : null;
        $dateMax = $msg->dateMax !== null ? new \DateTimeImmutable($msg->dateMax) : null;

        $this->recorder->recordExisting(
            entry: $entry,
            action: fn (RunHistory $run): FetchReport => $this->fetcher->fetch(
                apiKey: $msg->apiKey,
                lastFmUser: $msg->lastFmUser,
                dateMin: $dateMin,
                dateMax: $dateMax,
                maxScrobbles: $msg->maxScrobbles,
                dryRun: $msg->dryRun,
                progress: function (int $fetched, int $buffered, int $alreadyBuffered) use ($run, $msg): void {
                    $this->recorder->updateProgress(
                        $run,
                        current: $fetched,
                        total: $msg->maxScrobbles,
                        message: sprintf('%d récupérés · %d nouveaux · %d déjà bufferisés', $fetched, $buffered, $alreadyBuffered),
                    );
                },
            ),
            extractMetrics: static fn (FetchReport $r): array => [
                'fetched' => $r->fetched,
                'buffered' => $r->buffered,
                'already_buffered' => $r->alreadyBuffered,
                'dry_run' => $msg->dryRun,
                'date_min' => $dateMin?->format('Y-m-d'),
                'date_max' => $dateMax?->format('Y-m-d'),
            ],
        );
    }
}
