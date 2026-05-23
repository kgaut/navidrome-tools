<?php

namespace App\Command;

use App\Entity\RunHistory;
use App\Service\NavidromeStatsService;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:navidrome:stats:compute',
    description: 'Recompute the cached Navidrome stats snapshot (suitable for crontab).',
)]
class ComputeNavidromeStatsCommand extends Command
{
    public function __construct(
        private readonly NavidromeStatsService $statsService,
        private readonly RunHistoryRecorder $recorder,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $data = $this->recorder->record(
                type: RunHistory::TYPE_STATS,
                reference: 'navidrome',
                label: 'Navidrome stats compute',
                action: fn () => $this->statsService->compute(),
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Snapshot Navidrome recalculé : %d morceaux, %d artistes, %d albums, %d lectures.',
            $data['library']['tracks'] ?? 0,
            $data['library']['artists'] ?? 0,
            $data['library']['albums'] ?? 0,
            $data['total_plays'] ?? 0,
        ));

        return Command::SUCCESS;
    }
}
