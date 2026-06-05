<?php

namespace App\Command;

use App\Docker\NavidromeContainerException;
use App\Docker\NavidromeContainerManager;
use App\Entity\RunHistory;
use App\Entity\ScrobbleSync;
use App\Navidrome\NavidromeDbBackup;
use App\Navidrome\NavidromeRepository;
use App\Repository\ScrobbleSyncRepository;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:navidrome:wipe-scrobbles',
    description: '[DANGEROUS] Delete all Navidrome scrobbles, reset annotation play counts, and reset local sync tracking so everything can be re-imported.',
)]
class WipeNavidromeScrobblesCommand extends Command
{
    public function __construct(
        private readonly NavidromeRepository $navidrome,
        private readonly NavidromeDbBackup $backup,
        private readonly ScrobbleSyncRepository $syncRepo,
        private readonly RunHistoryRecorder $recorder,
        private readonly NavidromeContainerManager $container,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would be deleted/reset without writing anything.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip the interactive confirmation prompt.')
            ->addOption('auto-stop', null, InputOption::VALUE_NONE, 'Stop Navidrome before the wipe and restart it afterwards (always, even on error).')
            ->addOption('no-annotation-reset', null, InputOption::VALUE_NONE, 'Skip resetting annotation.play_count / play_date.')
            ->addOption('skip-pre-check', null, InputOption::VALUE_NONE, 'Skip the PRAGMA quick_check on navidrome.db before writing (use when quick_check reports a benign index mismatch).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');
        $autoStop = (bool) $input->getOption('auto-stop');
        $noAnnotationReset = (bool) $input->getOption('no-annotation-reset');
        $skipPreCheck = (bool) $input->getOption('skip-pre-check');

        if (!$dryRun && !$autoStop) {
            try {
                $this->container->assertSafeToWrite($force);
            } catch (NavidromeContainerException $e) {
                $io->error($e->getMessage());
                return Command::FAILURE;
            }
        }

        $userId = $this->navidrome->resolveUserId();

        $scrobbleCount = $this->navidrome->countUserScrobbles($userId);
        $annotationCount = $noAnnotationReset ? 0 : $this->navidrome->countAnnotationWithPlays($userId);
        $syncCount = $this->syncRepo->countByTargetStatus(ScrobbleSync::TARGET_NAVIDROME, ScrobbleSync::STATUS_MATCHED)
            + $this->syncRepo->countByTargetStatus(ScrobbleSync::TARGET_NAVIDROME, ScrobbleSync::STATUS_DUPLICATE)
            + $this->syncRepo->countByTargetStatus(ScrobbleSync::TARGET_NAVIDROME, ScrobbleSync::STATUS_UNMATCHED)
            + $this->syncRepo->countByTargetStatus(ScrobbleSync::TARGET_NAVIDROME, ScrobbleSync::STATUS_SKIPPED);

        $io->section('What will be wiped');
        $io->table(
            ['Table', 'Action', 'Rows'],
            [
                ['navidrome: scrobbles', 'DELETE WHERE user_id = ' . $userId, (string) $scrobbleCount],
                ['navidrome: annotation', $noAnnotationReset ? '(skipped)' : 'SET play_count=0, play_date=NULL', $noAnnotationReset ? '-' : (string) $annotationCount],
                ['local: scrobble_sync (navidrome)', 'Reset non-pending rows to pending', (string) $syncCount],
            ],
        );

        if ($dryRun) {
            $io->note('Dry-run — nothing written.');
            return Command::SUCCESS;
        }

        if (!$force) {
            $io->caution([
                'This will permanently delete all Navidrome scrobbles and reset play counts.',
                'A backup of navidrome.db will be created first.',
                'Type "yes" to confirm.',
            ]);
            $confirm = $io->ask('Confirm wipe');
            if ($confirm !== 'yes') {
                $io->warning('Aborted.');
                return Command::SUCCESS;
            }
        }

        $noAnnotation = $noAnnotationReset;

        $doWipe = function () use ($userId, $noAnnotation): array {
            $backupPath = $this->backup->backup();

            $this->navidrome->beginWriteTransaction();
            try {
                $deleted = $this->navidrome->deleteAllScrobbles($userId);
                $reset = $noAnnotation ? 0 : $this->navidrome->resetAnnotationPlayCounts($userId);
                $this->navidrome->commitWrite();
            } catch (\Throwable $e) {
                try {
                    $this->navidrome->rollbackWrite();
                } catch (\Throwable) {
                }
                throw $e;
            } finally {
                $this->navidrome->walCheckpointTruncate();
                $this->navidrome->closeWriteConnection();
            }

            $syncReset = $this->syncRepo->resetAllToPending(ScrobbleSync::TARGET_NAVIDROME);

            return [
                'backup' => $backupPath,
                'scrobbles_deleted' => $deleted,
                'annotations_reset' => $reset,
                'sync_reset' => $syncReset,
            ];
        };

        try {
            $result = $this->recorder->record(
                type: RunHistory::TYPE_NAVIDROME_WIPE,
                reference: 'navidrome',
                label: 'Navidrome scrobbles wipe',
                action: fn () => $autoStop
                    ? $this->container->runWithNavidromeStopped($doWipe, $skipPreCheck)
                    : $doWipe(),
                extractMetrics: static fn (array $r) => $r,
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Wipe done. scrobbles_deleted=%d annotations_reset=%d sync_rows_reset=%d backup=%s',
            $result['scrobbles_deleted'],
            $result['annotations_reset'],
            $result['sync_reset'],
            $result['backup'] ?? 'none',
        ));

        $io->note('Run app:scrobbles:sync-navidrome (with --auto-stop) to re-import from local Last.fm data.');

        return Command::SUCCESS;
    }
}
