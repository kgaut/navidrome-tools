<?php

namespace App\Command;

use App\Docker\NavidromeContainerException;
use App\Docker\NavidromeContainerManager;
use App\Entity\RunHistory;
use App\Navidrome\NavidromeSyncReport;
use App\Navidrome\NavidromeSyncService;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:scrobbles:sync-navidrome',
    description: 'Match pending scrobbles against Navidrome and insert them into the Navidrome scrobbles table.',
)]
class SyncNavidromeCommand extends Command
{
    public function __construct(
        private readonly NavidromeSyncService $syncService,
        private readonly RunHistoryRecorder $recorder,
        private readonly NavidromeContainerManager $container,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compute matches without writing to Navidrome.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Process at most N pending rows (0 = no limit).', '0')
            ->addOption('tolerance', null, InputOption::VALUE_REQUIRED, 'Dedup tolerance in seconds.', '60')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Bypass Navidrome container pre-flight check.')
            ->addOption('auto-stop', null, InputOption::VALUE_NONE, 'Stop Navidrome, sync, restart (always, even on error).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dryRun = (bool) $input->getOption('dry-run');
        $limit = max(0, (int) $input->getOption('limit'));
        $tolerance = max(0, (int) $input->getOption('tolerance'));
        $force = (bool) $input->getOption('force');
        $autoStop = (bool) $input->getOption('auto-stop');

        if (!$dryRun && !$autoStop) {
            try {
                $this->container->assertSafeToWrite($force);
            } catch (NavidromeContainerException $e) {
                $io->error($e->getMessage());
                return Command::FAILURE;
            }
        }

        $label = 'Navidrome sync' . ($dryRun ? ' [dry-run]' : '');

        try {
            $runProcess = fn () => $this->recorder->record(
                type: RunHistory::TYPE_NAVIDROME_SYNC,
                reference: 'scrobbles',
                label: $label,
                action: fn (RunHistory $entry) => $this->syncService->process(
                    limit: $limit,
                    dryRun: $dryRun,
                    toleranceSeconds: $tolerance,
                    run: $entry,
                    progress: function (int $c, int $m, int $d, int $u) use ($io): void {
                        $io->writeln(sprintf('  considered=%d matched=%d duplicates=%d unmatched=%d', $c, $m, $d, $u));
                    },
                ),
                extractMetrics: static fn (NavidromeSyncReport $r) => [
                    'prepared' => $r->prepared,
                    'considered' => $r->considered,
                    'matched' => $r->matched,
                    'duplicates' => $r->duplicates,
                    'unmatched' => $r->unmatched,
                    'skipped' => $r->skipped,
                    'backup' => $r->backupPath,
                    'dry_run' => $r->dryRun,
                ],
            );

            $report = $autoStop && !$dryRun
                ? $this->container->runWithNavidromeStopped($runProcess)
                : $runProcess();
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->newLine();
        $io->success(sprintf(
            '%s done. prepared=%d considered=%d matched=%d duplicates=%d unmatched=%d skipped=%d',
            $dryRun ? 'Dry-run' : 'Sync',
            $report->prepared,
            $report->considered,
            $report->matched,
            $report->duplicates,
            $report->unmatched,
            $report->skipped,
        ));

        return Command::SUCCESS;
    }
}
