<?php

namespace App\Command;

use App\Entity\RunHistory;
use App\LastFm\LovedStarredSyncService;
use App\LastFm\SyncReport;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:lastfm:sync-loved',
    description: 'Sync Last.fm loved tracks ↔ Navidrome starred tracks (adds-only, idempotent).',
)]
class SyncLovedStarredCommand extends Command
{
    public function __construct(
        private readonly LovedStarredSyncService $sync,
        private readonly RunHistoryRecorder $recorder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'direction',
                null,
                InputOption::VALUE_REQUIRED,
                'Sync direction: lf-to-nd, nd-to-lf, or both.',
                LovedStarredSyncService::DIRECTION_BOTH,
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Compute the diff and show what would be propagated, but write nothing.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $direction = (string) $input->getOption('direction');
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $report = $this->recorder->record(
                type: RunHistory::TYPE_LASTFM_LOVE_SYNC,
                reference: $direction,
                label: 'Sync loved/star — ' . $direction . ($dryRun ? ' [dry-run]' : ''),
                action: fn (RunHistory $entry) => $this->sync->sync($direction, $dryRun),
                extractMetrics: static fn (SyncReport $r) => [
                    'loved' => $r->lovedCount,
                    'starred' => $r->starredCount,
                    'common' => $r->commonCount,
                    'starred_added' => count($r->starredAdded),
                    'loved_added' => count($r->lovedAdded),
                    'unmatched' => count($r->lovedUnmatched),
                    'errors' => count($r->errors),
                    'direction' => $direction,
                    'dry_run' => $dryRun,
                ],
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            '%s done. loved=%d starred=%d common=%d  +starred=%d +loved=%d unmatched=%d errors=%d',
            $dryRun ? 'Dry-run' : 'Sync',
            $report->lovedCount,
            $report->starredCount,
            $report->commonCount,
            count($report->starredAdded),
            count($report->lovedAdded),
            count($report->lovedUnmatched),
            count($report->errors),
        ));

        if ($report->starredAdded !== []) {
            $io->section('Starred in Navidrome (lf → nd)');
            $io->listing(array_map(
                static fn (array $r) => sprintf('%s — %s  [mf=%s]', $r['artist'], $r['title'], $r['media_file_id']),
                array_slice($report->starredAdded, 0, 50),
            ));
        }
        if ($report->lovedAdded !== []) {
            $io->section('Loved on Last.fm (nd → lf)');
            $io->listing(array_map(
                static fn (array $r) => sprintf('%s — %s', $r['artist'], $r['title']),
                array_slice($report->lovedAdded, 0, 50),
            ));
        }
        if ($report->errors !== []) {
            $io->section('Soft errors');
            foreach ($report->errors as $e) {
                $io->writeln(sprintf('  [%s] %s — %s : %s', $e['action'], $e['artist'], $e['title'], $e['error']));
            }
        }

        return Command::SUCCESS;
    }
}
