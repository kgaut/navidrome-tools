<?php

namespace App\Command;

use App\Docker\NavidromeContainerManager;
use App\Entity\RunHistory;
use App\LastFm\LovesSyncReport;
use App\Service\LovesSyncService;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pull the user's Last.fm loved-tracks list and promote `annotation.starred=1`
 * on every matching media_file in Navidrome. One-way; never unstars
 * (« loved wins » policy means the inverse direction never deletes either).
 *
 * Writes to the Navidrome SQLite DB — pass --auto-stop, or stop the
 * Navidrome container manually first, otherwise the BEGIN IMMEDIATE
 * lock will fight with Navidrome's own writes.
 */
#[AsCommand(
    name: 'app:loves:lastfm-to-navidrome',
    description: 'Propagate Last.fm loved tracks to Navidrome starred status.',
)]
class SyncLovesLastFmToNavidromeCommand extends Command
{
    public function __construct(
        private readonly LovesSyncService $service,
        private readonly RunHistoryRecorder $recorder,
        private readonly NavidromeContainerManager $container,
        private readonly string $defaultApiKey,
        private readonly string $defaultUser,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('user', InputArgument::OPTIONAL, 'Last.fm username (defaults to LASTFM_USER).')
            ->addOption('api-key', null, InputOption::VALUE_REQUIRED, 'Last.fm API key (defaults to LASTFM_API_KEY).')
            ->addOption('auto-stop', null, InputOption::VALUE_NONE, 'Stop the Navidrome container during writes.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count what would change without writing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $user = (string) ($input->getArgument('user') ?: $this->defaultUser);
        $apiKey = (string) ($input->getOption('api-key') ?: $this->defaultApiKey);
        $autoStop = (bool) $input->getOption('auto-stop');
        $dryRun = (bool) $input->getOption('dry-run');

        if ($user === '' || $apiKey === '') {
            $io->error('LASTFM_USER and LASTFM_API_KEY are required (env or flags).');
            return Command::FAILURE;
        }

        $label = sprintf('Loves Last.fm→Navidrome %s%s', $user, $dryRun ? ' [dry-run]' : '');

        $runProcess = fn () => $this->recorder->record(
            type: RunHistory::TYPE_LOVES_LASTFM_TO_NAVIDROME,
            reference: $user,
            label: $label,
            action: fn () => $this->service->pullLastFmToNavidrome(
                $apiKey,
                $user,
                $dryRun,
                function (int $c, int $a, int $u) use ($io): void {
                    $io->writeln(sprintf('  considered=%d applied=%d unmatched=%d', $c, $a, $u));
                },
            ),
            extractMetrics: static fn (LovesSyncReport $r) => [
                'considered' => $r->considered,
                'applied' => $r->applied,
                'already_in_sync' => $r->alreadyInSync,
                'unmatched' => $r->unmatched,
                'errors' => $r->errors,
                'dry_run' => $dryRun,
            ],
        );

        try {
            $report = $autoStop && !$dryRun
                ? $this->container->runWithNavidromeStopped($runProcess)
                : $runProcess();
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->newLine();
        $io->success(sprintf(
            '%s : considered=%d applied=%d already_in_sync=%d unmatched=%d errors=%d',
            $dryRun ? 'Dry-run' : 'Done',
            $report->considered,
            $report->applied,
            $report->alreadyInSync,
            $report->unmatched,
            $report->errors,
        ));

        return Command::SUCCESS;
    }
}
