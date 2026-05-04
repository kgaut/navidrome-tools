<?php

namespace App\Command;

use App\Docker\NavidromeContainerException;
use App\Docker\NavidromeContainerManager;
use App\Entity\RunHistory;
use App\LastFm\LastFmBufferProcessor;
use App\LastFm\ProcessReport;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Drain the lastfm_import_buffer table: match buffered scrobbles against
 * Navidrome and insert successful matches into the scrobbles table. Each
 * processed buffer row is removed; the audit row is persisted in
 * lastfm_import_track.
 *
 * Writes to Navidrome's SQLite — Navidrome must be stopped.
 */
#[AsCommand(
    name: 'app:lastfm:process',
    description: 'Process the Last.fm import buffer: match scrobbles, insert them into Navidrome, then drop the buffer row.',
)]
class ProcessLastFmBufferCommand extends Command
{
    public function __construct(
        private readonly LastFmBufferProcessor $processor,
        private readonly RunHistoryRecorder $recorder,
        private readonly NavidromeContainerManager $container,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Compute matches without writing to Navidrome, persisting audit rows or deleting buffer rows.',
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum number of buffered scrobbles to process (0 = no limit).',
                '0',
            )
            ->addOption(
                'tolerance',
                null,
                InputOption::VALUE_REQUIRED,
                'Dedup tolerance in seconds for scrobbleExistsNear (default 60).',
                '60',
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Bypass the Navidrome container pre-flight check (only when NAVIDROME_CONTAINER_NAME is set). Use at your own risk: writing while Navidrome runs can corrupt the SQLite WAL.',
            )
            ->addOption(
                'auto-stop',
                null,
                InputOption::VALUE_NONE,
                'When NAVIDROME_CONTAINER_NAME is set, stop Navidrome before the process and restart it afterwards (always, even on error). No-op when the feature is disabled or when Navidrome is already stopped. Mutually exclusive with the default pre-flight check — pass this for unattended runs (cron).',
            );
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

        $label = 'Last.fm process buffer' . ($dryRun ? ' [dry-run]' : '');

        try {
            $runProcess = fn () => $this->recorder->record(
                type: RunHistory::TYPE_LASTFM_PROCESS,
                reference: 'buffer',
                label: $label,
                action: fn (RunHistory $entry) => $this->processor->process(
                    limit: $limit,
                    dryRun: $dryRun,
                    toleranceSeconds: $tolerance,
                    auditRun: $entry,
                    progress: function (int $c, int $i, int $d, int $u) use ($io): void {
                        $io->writeln(sprintf(
                            '  considered=%d  inserted=%d  duplicates=%d  unmatched=%d',
                            $c,
                            $i,
                            $d,
                            $u,
                        ));
                    },
                ),
                extractMetrics: static fn (ProcessReport $r) => [
                    'considered' => $r->considered,
                    'inserted' => $r->inserted,
                    'duplicates' => $r->duplicates,
                    'unmatched' => $r->unmatched,
                    'skipped' => $r->skipped,
                    'cache_hits_positive' => $r->cacheHitsPositive,
                    'cache_hits_negative' => $r->cacheHitsNegative,
                    'cache_misses' => $r->cacheMisses,
                    'limit' => $limit,
                    'dry_run' => $dryRun,
                ],
            );

            if ($autoStop && !$dryRun) {
                $report = $this->container->runWithNavidromeStopped($runProcess);
            } else {
                $report = $runProcess();
            }
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->newLine();
        $io->success(sprintf(
            '%s done. considered=%d inserted=%d duplicates=%d unmatched=%d skipped=%d',
            $dryRun ? 'Dry-run' : 'Process',
            $report->considered,
            $report->inserted,
            $report->duplicates,
            $report->unmatched,
            $report->skipped,
        ));

        return Command::SUCCESS;
    }
}
