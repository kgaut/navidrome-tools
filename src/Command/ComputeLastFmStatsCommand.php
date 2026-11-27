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
        private readonly string $historyUser,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Filter by Last.fm username (defaults to HISTORY_USER, empty = whole history).', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $user = $input->getOption('user') ?? ($this->historyUser !== '' ? $this->historyUser : null);
        $resolved = $this->statsService->resolveUser($user);
        $scope = $resolved ?? 'tout l\'historique';

        $io->writeln(sprintf('Calcul du snapshot pour <info>%s</info>…', $scope));

        try {
            $data = $this->recorder->record(
                type: RunHistory::TYPE_STATS,
                reference: 'lastfm',
                label: sprintf('Listening history stats compute (%s)', $scope),
                action: fn () => $this->statsService->compute($resolved),
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Snapshot de l\'historique d\'écoutes recalculé (%s) : %d écoutes, %d morceaux, %d artistes, %d albums, %d loved.',
            $scope,
            $data['library']['scrobbles'] ?? 0,
            $data['library']['tracks'] ?? 0,
            $data['library']['artists'] ?? 0,
            $data['library']['albums'] ?? 0,
            $data['loved_count'] ?? 0,
        ));

        return Command::SUCCESS;
    }
}
