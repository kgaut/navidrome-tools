<?php

namespace App\Command;

use App\Docker\NavidromeContainerManager;
use App\Entity\RunHistory;
use App\LastFm\LovesSyncReport;
use App\Loves\ActiveLovesProvider;
use App\Service\LovesSyncService;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Loved tracks of the active source (SCROBBLE_SOURCE) → Navidrome starred
 * (issue #310). Never unstars. Writes to the Navidrome DB: pass --auto-stop.
 */
#[AsCommand(
    name: 'app:loves:pull',
    description: 'Star in Navidrome the tracks loved on the active source (SCROBBLE_SOURCE).',
)]
class LovesPullCommand extends Command
{
    public function __construct(
        private readonly ActiveLovesProvider $providers,
        private readonly LovesSyncService $service,
        private readonly RunHistoryRecorder $recorder,
        private readonly NavidromeContainerManager $container,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('auto-stop', null, InputOption::VALUE_NONE, 'Stop the Navidrome container during writes.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count what would change without writing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $provider = $this->providers->get();
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
        if (!$provider->isConfigured()) {
            $io->error(sprintf('Source « %s » non configurée.', $provider->name()));
            return Command::FAILURE;
        }

        $runProcess = fn () => $this->recorder->record(
            type: RunHistory::TYPE_LOVES_PULL,
            reference: $provider->user(),
            label: sprintf('Loves %s→Navidrome %s%s', $provider->name(), $provider->user(), $dryRun ? ' [dry-run]' : ''),
            action: fn () => $this->service->pullToNavidrome($provider, $dryRun, function (int $c, int $a, int $u) use ($io): void {
                $io->writeln(sprintf('  considered=%d applied=%d unmatched=%d', $c, $a, $u));
            }),
            extractMetrics: static fn (LovesSyncReport $r) => LovesPushCommand::metrics($r, $provider->name(), $dryRun),
        );

        try {
            $report = (bool) $input->getOption('auto-stop') && !$dryRun
                ? $this->container->runWithNavidromeStopped($runProcess)
                : $runProcess();
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success(LovesPushCommand::summary($report, $dryRun));

        return Command::SUCCESS;
    }
}
