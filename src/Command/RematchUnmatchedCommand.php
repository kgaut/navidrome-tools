<?php

namespace App\Command;

use App\Docker\NavidromeContainerException;
use App\Docker\NavidromeContainerManager;
use App\Entity\RunHistory;
use App\LastFm\RematchReport;
use App\Service\LastFmRematchService;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:lastfm:rematch',
    description: 'Re-apply the matching cascade to previously unmatched Last.fm scrobbles and insert successful matches into Navidrome.',
)]
class RematchUnmatchedCommand extends Command
{
    public function __construct(
        private readonly LastFmRematchService $rematch,
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
                'Compute matches without writing to Navidrome or updating lastfm_import_track.',
            )
            ->addOption(
                'run-id',
                null,
                InputOption::VALUE_REQUIRED,
                'Limit rematch to a single run_history id.',
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum number of unmatched rows to consider (0 = no limit).',
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
                'random',
                null,
                InputOption::VALUE_NONE,
                'Process unmatched rows in random order (combine with --limit to sample a subset).',
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Bypass the Navidrome container pre-flight check (only when NAVIDROME_CONTAINER_NAME is set). Use at your own risk: writing while Navidrome runs can corrupt the SQLite WAL.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $runId = $input->getOption('run-id');
        $runId = $runId !== null ? (int) $runId : null;
        $limit = max(0, (int) $input->getOption('limit'));
        $tolerance = max(0, (int) $input->getOption('tolerance'));
        $random = (bool) $input->getOption('random');
        $force = (bool) $input->getOption('force');

        if (!$dryRun) {
            try {
                $this->container->assertSafeToWrite($force);
            } catch (NavidromeContainerException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
        }

        $reference = $runId !== null ? 'run-' . $runId : 'all';
        $label = 'Rematch unmatched — ' . $reference . ($dryRun ? ' [dry-run]' : '');

        try {
            $report = $this->recorder->record(
                type: RunHistory::TYPE_LASTFM_REMATCH,
                reference: $reference,
                label: $label,
                action: fn (RunHistory $entry) => $this->rematch->rematch(
                    runId: $runId,
                    limit: $limit,
                    dryRun: $dryRun,
                    toleranceSeconds: $tolerance,
                    random: $random,
                ),
                extractMetrics: static fn (RematchReport $r) => [
                    'considered' => $r->considered,
                    'inserted' => $r->matchedAsInserted,
                    'duplicate' => $r->matchedAsDuplicate,
                    'skipped' => $r->skipped,
                    'still_unmatched' => $r->stillUnmatched,
                    'cache_hits_positive' => $r->cacheHitsPositive,
                    'cache_hits_negative' => $r->cacheHitsNegative,
                    'cache_misses' => $r->cacheMisses,
                    'run_id_filter' => $runId,
                    'limit' => $limit,
                    'dry_run' => $dryRun,
                    'random' => $random,
                ],
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            '%s done. considered=%d inserted=%d duplicate=%d skipped=%d still-unmatched=%d',
            $dryRun ? 'Dry-run' : 'Rematch',
            $report->considered,
            $report->matchedAsInserted,
            $report->matchedAsDuplicate,
            $report->skipped,
            $report->stillUnmatched,
        ));

        return Command::SUCCESS;
    }
}
