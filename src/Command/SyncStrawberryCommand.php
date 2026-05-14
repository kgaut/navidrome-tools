<?php

namespace App\Command;

use App\Entity\RunHistory;
use App\Strawberry\StrawberrySyncReport;
use App\Strawberry\StrawberrySyncService;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:scrobbles:sync-strawberry',
    description: 'Sync pending scrobbles into the Strawberry music player database (playcount / lastplayed).',
)]
class SyncStrawberryCommand extends Command
{
    public function __construct(
        private readonly StrawberrySyncService $syncService,
        private readonly RunHistoryRecorder $recorder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Match without writing to Strawberry.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Process at most N pending rows (0 = no limit).', '0')
            ->addOption('retry-unmatched', null, InputOption::VALUE_NONE, 'Also retry previously unmatched rows.')
            ->addOption('db-path', null, InputOption::VALUE_REQUIRED, 'Override STRAWBERRY_DB_PATH with a specific file.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dryRun = (bool) $input->getOption('dry-run');
        $limit = max(0, (int) $input->getOption('limit'));
        $retryUnmatched = (bool) $input->getOption('retry-unmatched');

        $label = 'Strawberry sync' . ($dryRun ? ' [dry-run]' : '') . ($retryUnmatched ? ' +retry' : '');

        try {
            $report = $this->recorder->record(
                type: RunHistory::TYPE_STRAWBERRY_SYNC,
                reference: 'scrobbles',
                label: $label,
                action: fn (RunHistory $entry) => $this->syncService->process(
                    limit: $limit,
                    dryRun: $dryRun,
                    retryUnmatched: $retryUnmatched,
                    run: $entry,
                    progress: function (int $c, int $m, int $u) use ($io): void {
                        $io->writeln(sprintf('  considered=%d matched=%d unmatched=%d', $c, $m, $u));
                    },
                ),
                extractMetrics: static fn (StrawberrySyncReport $r) => [
                    'prepared' => $r->prepared,
                    'considered' => $r->considered,
                    'matched' => $r->matched,
                    'unmatched' => $r->unmatched,
                    'dry_run' => $r->dryRun,
                    'retry_unmatched' => $r->retryUnmatched,
                ],
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            '%s done. prepared=%d considered=%d matched=%d unmatched=%d',
            $dryRun ? 'Dry-run' : 'Sync',
            $report->prepared,
            $report->considered,
            $report->matched,
            $report->unmatched,
        ));

        return Command::SUCCESS;
    }
}
