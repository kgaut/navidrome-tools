<?php

namespace App\Command;

use App\Entity\RunHistory;
use App\Service\LocalStatsService;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:stats:compute',
    description: 'Compute and cache listening statistics from the local scrobbles table.',
)]
class ComputeStatsCommand extends Command
{
    public function __construct(
        private readonly LocalStatsService $statsService,
        private readonly RunHistoryRecorder $recorder,
        private readonly string $defaultUser,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('period', 'p', InputOption::VALUE_REQUIRED, 'Single period to compute (default: all).', null)
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Filter by Last.fm username.', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $user = $input->getOption('user') ?? ($this->defaultUser !== '' ? $this->defaultUser : null);
        $periodOpt = $input->getOption('period');
        $periods = $periodOpt !== null ? [$periodOpt] : array_keys(LocalStatsService::PERIODS);

        try {
            $this->recorder->record(
                type: RunHistory::TYPE_STATS,
                reference: 'local',
                label: 'Stats compute',
                action: function () use ($periods, $user, $io): void {
                    foreach ($periods as $period) {
                        $data = $this->statsService->compute($period, $user);
                        $io->writeln(sprintf(
                            '  %s : %d plays, %d top artists, %d top tracks',
                            $period,
                            $data['total_plays'],
                            count($data['top_artists']),
                            count($data['top_tracks']),
                        ));
                    }
                },
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf('Stats computed for %d period(s).', count($periods)));
        return Command::SUCCESS;
    }
}
