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

/**
 * Snapshot the tool's local SQLite DB to var/backups/. Designed to run
 * unattended via cron (`DB_BACKUP_SCHEDULE`) — Doctrine SQLite handles
 * concurrent access so we don't need to stop the web container.
 */
#[AsCommand(
    name: 'app:db:backup',
    description: 'Snapshot the tool\'s local SQLite database to var/backups/.',
)]
class BackupDataDbCommand extends Command
{
    public function __construct(
        private readonly BackupService $backupService,
        private readonly RunHistoryRecorder $recorder,
        private readonly string $projectDir,
        private readonly int $retentionDays,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'retention-days',
                null,
                InputOption::VALUE_REQUIRED,
                'Override DB_BACKUP_RETENTION_DAYS for this run (0 = keep forever).',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $retention = $input->getOption('retention-days');
        $retentionDays = $retention !== null ? max(0, (int) $retention) : $this->retentionDays;

        $sourcePath = $this->projectDir . '/var/data.db';
        $backupDir = $this->projectDir . '/var/backups';

        try {
            $result = $this->recorder->record(
                type: RunHistory::TYPE_DB_BACKUP,
                reference: 'data.db',
                label: 'Backup DB locale',
                action: function () use ($sourcePath, $backupDir, $retentionDays): array {
                    $snapshot = $this->backupService->backupSqlite($sourcePath, $backupDir, 'data');
                    $pruned = $this->backupService->pruneOlderThan($backupDir, 'data', $retentionDays);

                    return $snapshot + ['pruned' => $pruned];
                },
                extractMetrics: static fn (array $r): array => [
                    'size_bytes' => $r['size'],
                    'pruned' => $r['pruned'],
                    'path' => $r['path'],
                ],
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Backup OK : %s (%s, %d ancien(s) purgé(s))',
            basename($result['path']),
            $this->formatBytes($result['size']),
            $result['pruned'],
        ));

        return Command::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' o';
        }
        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1, ',', ' ') . ' Kio';
        }

        return number_format($bytes / 1024 / 1024, 1, ',', ' ') . ' Mio';
    }
}
