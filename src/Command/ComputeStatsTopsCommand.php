<?php

namespace App\Command;

use App\Entity\RunHistory;
use App\Entity\TopSnapshot;
use App\Service\RunHistoryRecorder;
use App\Service\TopsService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:stats:tops:compute',
    description: 'Compute / refresh a top-snapshot (artists / albums / tracks) for an arbitrary date window.',
)]
class ComputeStatsTopsCommand extends Command
{
    public function __construct(
        private readonly TopsService $tops,
        private readonly RunHistoryRecorder $recorder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Window start (Y-m-d). Defaults to 30 days ago.')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Window end (Y-m-d, inclusive). Defaults to today.')
            ->addOption('client', null, InputOption::VALUE_REQUIRED, 'Filter by Subsonic client (optional).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $now = new \DateTimeImmutable('now');
        $fromOpt = $input->getOption('from');
        $toOpt = $input->getOption('to');
        $client = $input->getOption('client');
        $clientFilter = ($client !== null && $client !== '') ? (string) $client : null;

        try {
            $from = $fromOpt !== null ? new \DateTimeImmutable((string) $fromOpt) : $now->modify('-30 days');
            $to = $toOpt !== null ? new \DateTimeImmutable((string) $toOpt) : $now;
        } catch (\Throwable $e) {
            $io->error('Invalid date: ' . $e->getMessage());

            return Command::FAILURE;
        }

        [$normFrom, $normTo] = TopsService::normalizeWindow($from, $to);
        $reference = sprintf('%s..%s', $normFrom->format('Y-m-d'), $normTo->format('Y-m-d'));
        if ($clientFilter !== null) {
            $reference .= '|' . $clientFilter;
        }

        try {
            $snapshot = $this->recorder->record(
                type: RunHistory::TYPE_STATS_TOPS,
                reference: $reference,
                label: 'Tops — ' . $reference,
                action: fn () => $this->tops->compute($from, $to, $clientFilter),
                extractMetrics: static fn (TopSnapshot $s) => [
                    'total_plays' => $s->getData()['total_plays'] ?? 0,
                    'distinct_tracks' => $s->getData()['distinct_tracks'] ?? 0,
                    'top_artists' => count($s->getData()['top_artists'] ?? []),
                    'top_albums' => count($s->getData()['top_albums'] ?? []),
                    'top_tracks' => count($s->getData()['top_tracks'] ?? []),
                ],
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Tops computed for %s: %d plays, %d distinct tracks, %d artists / %d albums / %d tracks.',
            $reference,
            $snapshot->getData()['total_plays'] ?? 0,
            $snapshot->getData()['distinct_tracks'] ?? 0,
            count($snapshot->getData()['top_artists'] ?? []),
            count($snapshot->getData()['top_albums'] ?? []),
            count($snapshot->getData()['top_tracks'] ?? []),
        ));

        return Command::SUCCESS;
    }
}
