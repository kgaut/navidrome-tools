<?php

namespace App\Command;

use App\Docker\NavidromeContainerException;
use App\Docker\NavidromeContainerManager;
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
 * Snapshot the Navidrome library DB. Navidrome must be stopped during
 * the VACUUM INTO so the SQLite file isn't being mutated mid-snapshot
 * — we either rely on a manual pre-flight (default) or orchestrate the
 * stop / backup / restart cycle ourselves with `--auto-stop`. Mirrors
 * the pattern of `app:lastfm:import`.
 */
#[AsCommand(
    name: 'app:navidrome:backup',
    description: 'Snapshot the Navidrome library SQLite database to var/backups/.',
)]
class BackupNavidromeDbCommand extends Command
{
    public function __construct(
        private readonly BackupService $backupService,
        private readonly RunHistoryRecorder $recorder,
        private readonly NavidromeContainerManager $container,
        private readonly string $navidromeDbPath,
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
                'Override NAVIDROME_BACKUP_RETENTION_DAYS for this run (0 = keep forever).',
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Skip the Navidrome container pre-flight check. Use at your own risk: copying the DB while Navidrome runs can produce a corrupted snapshot.',
            )
            ->addOption(
                'auto-stop',
                null,
                InputOption::VALUE_NONE,
                'When NAVIDROME_CONTAINER_NAME is set, stop Navidrome before the backup and restart it afterwards (always, even on error). Recommended for unattended runs (cron).',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $retention = $input->getOption('retention-days');
        $retentionDays = $retention !== null ? max(0, (int) $retention) : $this->retentionDays;
        $force = (bool) $input->getOption('force');
        $autoStop = (bool) $input->getOption('auto-stop');

        if ($this->navidromeDbPath === '') {
            $io->error('NAVIDROME_DB_PATH n\'est pas renseignée — impossible de localiser la DB Navidrome à sauvegarder.');

            return Command::FAILURE;
        }

        if (!$autoStop) {
            try {
                $this->container->assertSafeToWrite($force);
            } catch (NavidromeContainerException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
        }

        $backupDir = $this->projectDir . '/var/backups';
        $sourcePath = $this->navidromeDbPath;

        $runBackup = fn () => $this->recorder->record(
            type: RunHistory::TYPE_NAVIDROME_BACKUP,
            reference: basename($sourcePath),
            label: 'Backup DB Navidrome',
            action: function () use ($sourcePath, $backupDir, $retentionDays): array {
                $snapshot = $this->backupService->backupSqlite($sourcePath, $backupDir, 'navidrome');
                $pruned = $this->backupService->pruneOlderThan($backupDir, 'navidrome', $retentionDays);

                return $snapshot + ['pruned' => $pruned];
            },
            extractMetrics: static fn (array $r): array => [
                'size_bytes' => $r['size'],
                'pruned' => $r['pruned'],
                'path' => $r['path'],
            ],
        );

        try {
            $result = $autoStop
                ? $this->container->runWithNavidromeStopped($runBackup)
                : $runBackup();
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
