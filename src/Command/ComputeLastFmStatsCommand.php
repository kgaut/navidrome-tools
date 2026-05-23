<?php

namespace App\Command;

use App\Entity\RunHistory;
use App\Service\LastFmStatsService;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:lastfm:stats:compute',
    description: 'Recompute the cached Last.fm stats snapshot (suitable for crontab).',
)]
class ComputeLastFmStatsCommand extends Command
{
    public function __construct(
        private readonly LastFmStatsService $statsService,
        private readonly RunHistoryRecorder $recorder,
        private readonly string $defaultUser,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Filter by Last.fm username (defaults to LASTFM_USER).', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $user = $input->getOption('user') ?? ($this->defaultUser !== '' ? $this->defaultUser : null);

        try {
            $data = $this->recorder->record(
                type: RunHistory::TYPE_STATS,
                reference: 'lastfm',
                label: 'Last.fm stats compute',
                action: fn () => $this->statsService->compute($user),
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Snapshot Last.fm recalculé : %d scrobbles, %d morceaux, %d artistes, %d albums, %d loved.',
            $data['library']['scrobbles'] ?? 0,
            $data['library']['tracks'] ?? 0,
            $data['library']['artists'] ?? 0,
            $data['library']['albums'] ?? 0,
            $data['loved_count'] ?? 0,
        ));

        return Command::SUCCESS;
    }
}
