<?php

namespace App\Command;

use App\Entity\RunHistory;
use App\Service\BackupService;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:backup:purge',
    description: 'Delete backup files older than BACKUP_RETENTION_DAYS days.',
)]
class PurgeBackupsCommand extends Command
{
    public function __construct(
        private readonly BackupService $backupService,
        private readonly RunHistoryRecorder $recorder,
        private readonly int $retentionDays,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Override retention days.', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = (int) ($input->getOption('days') ?? $this->retentionDays);

        try {
            $removed = $this->recorder->record(
                type: RunHistory::TYPE_BACKUP_PURGE,
                reference: 'backups',
                label: sprintf('Purge backups > %d days', $days),
                action: fn () => $this->backupService->pruneOlderThan($days),
                extractMetrics: static fn (int $n) => ['removed' => $n, 'retention_days' => $days],
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf('Removed %d backup file(s) older than %d days.', $removed, $days));
        return Command::SUCCESS;
    }
}
