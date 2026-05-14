<?php

namespace App\Command;

use App\Entity\RunHistory;
use App\Repository\LastFmBufferedScrobbleRepository;
use App\Service\RunHistoryRecorder;
use App\Strawberry\StrawberryBufferProcessor;
use App\Strawberry\StrawberryProcessReport;
use App\Strawberry\StrawberryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sync unprocessed Last.fm buffer rows to the Strawberry music player database.
 * For each matched song, playcount is incremented and lastplayed updated.
 * Unmatched rows are left for automatic retry on the next run.
 */
#[AsCommand(
    name: 'app:strawberry:process',
    description: 'Sync the Last.fm import buffer to the Strawberry music player database (playcount/lastplayed).',
)]
class ProcessStrawberryBufferCommand extends Command
{
    public function __construct(
        private readonly StrawberryBufferProcessor $processor,
        private readonly RunHistoryRecorder $recorder,
        private readonly LastFmBufferedScrobbleRepository $bufferRepo,
        private readonly EntityManagerInterface $em,
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
                'Compute matches without writing to Strawberry or marking buffer rows synced.',
            )
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_REQUIRED,
                'Maximum number of buffered scrobbles to process (0 = no limit).',
                '0',
            )
            ->addOption(
                'db-path',
                null,
                InputOption::VALUE_REQUIRED,
                'Override STRAWBERRY_DB_PATH with a specific file path (e.g. for an uploaded DB).',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dryRun = (bool) $input->getOption('dry-run');
        $limit = max(0, (int) $input->getOption('limit'));
        $dbPathOverride = (string) ($input->getOption('db-path') ?? '');

        $processor = $this->processor;
        $reference = 'buffer';

        if ($dbPathOverride !== '') {
            if (!file_exists($dbPathOverride)) {
                $io->error(sprintf('File not found: %s', $dbPathOverride));

                return Command::FAILURE;
            }
            $processor = new StrawberryBufferProcessor(
                $this->bufferRepo,
                new StrawberryRepository($dbPathOverride),
                $this->em,
            );
            $reference = basename($dbPathOverride);
        }

        $label = 'Strawberry process buffer' . ($dryRun ? ' [dry-run]' : '');

        try {
            $report = $this->recorder->record(
                type: RunHistory::TYPE_STRAWBERRY_PROCESS,
                reference: $reference,
                label: $label,
                action: fn (RunHistory $entry) => $processor->process(
                    limit: $limit,
                    dryRun: $dryRun,
                    auditRun: $entry,
                    progress: function (int $c, int $m, int $u) use ($io): void {
                        $io->writeln(sprintf(
                            '  considered=%d  matched=%d  unmatched=%d',
                            $c,
                            $m,
                            $u,
                        ));
                    },
                ),
                extractMetrics: static fn (StrawberryProcessReport $r) => [
                    'considered' => $r->considered,
                    'matched' => $r->matched,
                    'unmatched' => $r->unmatched,
                    'limit' => $limit,
                    'dry_run' => $dryRun,
                ],
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->newLine();
        $io->success(sprintf(
            '%s done. considered=%d matched=%d unmatched=%d',
            $dryRun ? 'Dry-run' : 'Process',
            $report->considered,
            $report->matched,
            $report->unmatched,
        ));

        return Command::SUCCESS;
    }
}
