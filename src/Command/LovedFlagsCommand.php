<?php

namespace App\Command;

use App\Entity\RunHistory;
use App\LastFm\LovedSyncReport;
use App\Loves\ActiveLovesProvider;
use App\Loves\LovedFlagsSync;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Flag `scrobbles.loved` from the loved tracks of the active source
 * (SCROBBLE_SOURCE), issue #310. Local DB only.
 */
#[AsCommand(
    name: 'app:loves:flag-scrobbles',
    description: 'Flag stored listens as loved from the active source (SCROBBLE_SOURCE).',
)]
class LovedFlagsCommand extends Command
{
    public function __construct(
        private readonly ActiveLovesProvider $providers,
        private readonly LovedFlagsSync $sync,
        private readonly RunHistoryRecorder $recorder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count matches without writing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $provider = $this->providers->get();
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
        if (!$provider->isConfigured()) {
            $io->error(sprintf('Source « %s » non configurée.', $provider->name()));
            return Command::FAILURE;
        }

        try {
            $report = $this->recorder->record(
                type: RunHistory::TYPE_LOVED_FLAGS,
                reference: $provider->user(),
                label: sprintf('Loved flags %s %s%s', $provider->name(), $provider->user(), $dryRun ? ' [dry-run]' : ''),
                action: fn () => $this->sync->sync($provider, $dryRun),
                extractMetrics: static fn (LovedSyncReport $r) => [
                    'source' => $provider->name(),
                    'fetched' => $r->fetched,
                    'matched' => $r->matched,
                    'unmatched' => $r->unmatched,
                    'updated_rows' => $r->updatedRows,
                    'dry_run' => $dryRun,
                ],
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            '%s : fetched=%d matched=%d unmatched=%d updated_rows=%d',
            $dryRun ? 'Dry-run' : 'Done',
            $report->fetched,
            $report->matched,
            $report->unmatched,
            $report->updatedRows,
        ));

        return Command::SUCCESS;
    }
}
