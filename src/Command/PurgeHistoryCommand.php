<?php

namespace App\Command;

use App\Entity\RunHistory;
use App\Repository\RunHistoryRepository;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:history:purge',
    description: 'Delete run_history entries older than RUN_HISTORY_RETENTION_DAYS days.',
)]
class PurgeHistoryCommand extends Command
{
    public function __construct(
        private readonly RunHistoryRepository $runHistoryRepo,
        private readonly RunHistoryRecorder $recorder,
        private readonly int $retentionDays,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $cutoff = new \DateTimeImmutable(sprintf('-%d days', $this->retentionDays));

        try {
            $deleted = $this->recorder->record(
                type: RunHistory::TYPE_HISTORY_PURGE,
                reference: 'run_history',
                label: sprintf('Purge history > %d days', $this->retentionDays),
                action: fn () => $this->runHistoryRepo->purgeOlderThan($cutoff),
                extractMetrics: fn (int $n) => ['deleted' => $n, 'retention_days' => $this->retentionDays],
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf('Deleted %d run_history entries older than %d days.', $deleted, $this->retentionDays));
        return Command::SUCCESS;
    }
}
