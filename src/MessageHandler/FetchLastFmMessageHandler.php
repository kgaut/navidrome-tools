<?php

namespace App\MessageHandler;

use App\Entity\RunHistory;
use App\LastFm\FetchReport;
use App\LastFm\LastFmFetcher;
use App\Message\FetchLastFmMessage;
use App\Repository\SettingRepository;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class FetchLastFmMessageHandler
{
    private const SETTING_KEY_PREFIX = 'lastfm_last_fetch_';

    public function __construct(
        private readonly LastFmFetcher $fetcher,
        private readonly RunHistoryRecorder $recorder,
        private readonly SettingRepository $settings,
    ) {
    }

    public function __invoke(FetchLastFmMessage $message): void
    {
        $dateMin = $message->dateMin !== null ? new \DateTimeImmutable($message->dateMin) : null;
        $dateMax = $message->dateMax !== null ? new \DateTimeImmutable($message->dateMax) : null;
        $smartDate = $message->dateMin === null;
        $now = new \DateTimeImmutable();

        // Apply smart date if no explicit date-min.
        if ($smartDate) {
            $lastFetch = $this->settings->get(self::SETTING_KEY_PREFIX . $message->user);
            if ($lastFetch !== '') {
                $dateMin = (new \DateTimeImmutable($lastFetch))->modify('-1 hours');
            }
        }

        $report = $this->recorder->record(
            type: RunHistory::TYPE_LASTFM_FETCH,
            reference: $message->user,
            label: sprintf('Last.fm fetch %s%s', $message->user, $message->dryRun ? ' [dry-run]' : ''),
            action: fn () => $this->fetcher->fetch(
                apiKey: $message->apiKey,
                lastFmUser: $message->user,
                dateMin: $dateMin,
                dateMax: $dateMax,
                maxScrobbles: $message->maxScrobbles,
                dryRun: $message->dryRun,
            ),
            extractMetrics: static fn (FetchReport $r) => [
                'fetched' => $r->fetched,
                'inserted' => $r->inserted,
                'duplicates' => $r->duplicates,
                'smart_date' => $smartDate,
                'dry_run' => $message->dryRun,
            ],
        );

        if ($smartDate && !$message->dryRun && $report->fetched > 0) {
            $this->settings->set(self::SETTING_KEY_PREFIX . $message->user, $now->format('Y-m-d H:i:s'));
        }
    }
}
