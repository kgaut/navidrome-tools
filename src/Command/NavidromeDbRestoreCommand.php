<?php

namespace App\Command;

use App\Docker\NavidromeContainerManager;
use App\Navidrome\NavidromeDbBackup;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Restore the Navidrome SQLite DB from one of the auto-snapshots taken
 * before each `app:lastfm:process` / `app:lastfm:rematch --auto-stop`
 * action. By default uses the most recent snapshot.
 *
 * Wraps the action with `NavidromeContainerManager::runWithNavidromeStopped(...,
 * skipPreCheck: true)` so it works on a DB that the standard pre-check
 * would refuse to open — that's the whole point of this command. The
 * post-action quick_check on the *restored* file still runs, so a
 * silently-bad snapshot would surface immediately.
 */
#[AsCommand(
    name: 'app:navidrome:db:restore',
    description: 'Restaurer la DB Navidrome depuis un snapshot pris automatiquement (par défaut le plus récent).',
)]
class NavidromeDbRestoreCommand extends Command
{
    public function __construct(
        private readonly NavidromeDbBackup $backup,
        private readonly NavidromeContainerManager $container,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'timestamp',
                null,
                InputOption::VALUE_REQUIRED,
                'Timestamp du backup à restaurer (YYYYMMDDHHMMSS). Sans cette option, utilise le plus récent.',
            )
            ->addOption(
                'list',
                null,
                InputOption::VALUE_NONE,
                'Lister les backups disponibles puis sortir, sans rien restaurer.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ((bool) $input->getOption('list')) {
            return $this->showList($io);
        }

        $timestamp = $input->getOption('timestamp');
        $latest = $this->backup->latestBackup();

        if ($timestamp === null && $latest === null) {
            $io->error('Aucun backup disponible.');
            return Command::FAILURE;
        }

        $effective = is_string($timestamp) && $timestamp !== '' ? $timestamp : (string) $latest;

        if (
            $input->isInteractive() && !$io->confirm(
                sprintf(
                    'Restaurer la DB Navidrome depuis le backup %s ? Le contenu actuel sera écrasé.',
                    $effective,
                ),
                false,
            )
        ) {
            $io->warning('Annulation.');
            return Command::FAILURE;
        }

        try {
            $restoredFrom = $this->container->runWithNavidromeStopped(
                fn (): string => $this->backup->restore(is_string($timestamp) && $timestamp !== '' ? $timestamp : null),
                skipPreCheck: true,
            );
        } catch (\Throwable $e) {
            $io->error(sprintf('Restore échouée : %s', $e->getMessage()));
            return Command::FAILURE;
        }

        $io->success(sprintf('DB restaurée depuis %s.', $restoredFrom));
        return Command::SUCCESS;
    }

    private function showList(SymfonyStyle $io): int
    {
        $backups = $this->backup->listBackups();
        if ($backups === []) {
            $io->writeln('Aucun backup disponible.');
            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($backups as $path) {
            preg_match('/\.backup-(\d+)$/', $path, $m);
            $size = @filesize($path);
            $rows[] = [
                $m[1] ?? '?',
                $path,
                $size !== false ? $this->humanSize($size) : '?',
            ];
        }
        $io->table(['Timestamp', 'Path', 'Size'], $rows);
        $io->writeln(sprintf('%d backup(s) disponible(s).', count($backups)));

        return Command::SUCCESS;
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return sprintf('%.1f %s', $size, $units[$i]);
    }
}
