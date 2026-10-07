<?php

namespace App\Command;

use App\AudioMuse\AudioFeatureSync;
use App\AudioMuse\AudioFeatureSyncReport;
use App\AudioMuse\AudioMuseClient;
use App\Entity\RunHistory;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Import AudioMuse-AI audio features (tempo, energy, moods, genres) into
 * the local DB, incrementally (issue #257). Meant to run right before the
 * playlist generation that uses them (scripts/navidrome-playlists.sh).
 */
#[AsCommand(
    name: 'app:audiomuse:sync',
    description: 'Import AudioMuse-AI audio features (tempo, energy, moods) into the local DB.',
)]
class SyncAudioMuseFeaturesCommand extends Command
{
    public function __construct(
        private readonly AudioFeatureSync $sync,
        private readonly AudioMuseClient $audioMuse,
        private readonly RunHistoryRecorder $recorder,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->audioMuse->isConfigured()) {
            $io->note('AUDIOMUSE_BASE_URL is empty: nothing to sync.');

            return Command::SUCCESS;
        }

        try {
            $report = $this->recorder->record(
                type: RunHistory::TYPE_AUDIOMUSE_SYNC,
                reference: 'audiomuse',
                label: 'AudioMuse : import des caractéristiques audio',
                action: fn () => $this->sync->run(),
                extractMetrics: static fn (AudioFeatureSyncReport $r) => [
                    'total' => $r->total,
                    'added' => $r->added,
                    'updated' => $r->updated,
                    'removed' => $r->removed,
                    'unchanged' => $r->unchanged,
                ],
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            '%d tracks known to AudioMuse: %d added, %d updated, %d removed, %d unchanged.',
            $report->total,
            $report->added,
            $report->updated,
            $report->removed,
            $report->unchanged,
        ));

        return Command::SUCCESS;
    }
}
