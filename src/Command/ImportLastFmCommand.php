<?php

namespace App\Command;

use App\LastFm\LastFmImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:lastfm:import',
    description: 'One-shot import of a Last.fm scrobble history into Navidrome.',
)]
class ImportLastFmCommand extends Command
{
    public function __construct(
        private readonly LastFmImporter $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'lastfm-user',
                InputArgument::REQUIRED,
                'Last.fm username whose scrobbles you want to import.',
            )
            ->addOption(
                'api-key',
                null,
                InputOption::VALUE_REQUIRED,
                'Last.fm API key (defaults to LASTFM_API_KEY env var).',
            )
            ->addOption(
                'from',
                null,
                InputOption::VALUE_REQUIRED,
                'Only import scrobbles after this date (YYYY-MM-DD or ISO).',
            )
            ->addOption(
                'to',
                null,
                InputOption::VALUE_REQUIRED,
                'Only import scrobbles before this date (YYYY-MM-DD or ISO).',
            )
            ->addOption(
                'tolerance',
                null,
                InputOption::VALUE_REQUIRED,
                'Dedup tolerance window in seconds (default 60).',
                '60',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Fetch + match + count, but do not write to the Navidrome DB.',
            )
            ->addOption(
                'show-unmatched',
                null,
                InputOption::VALUE_REQUIRED,
                'How many unmatched (artist, title) pairs to print, ordered by scrobble count DESC. 0 to hide. "all" to show everything.',
                '50',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $apiKey = (string) ($input->getOption('api-key') ?? $_ENV['LASTFM_API_KEY'] ?? getenv('LASTFM_API_KEY') ?: '');
        if ($apiKey === '') {
            $io->error('Provide an API key with --api-key or LASTFM_API_KEY env var. Get one at https://www.last.fm/api/account/create');

            return Command::FAILURE;
        }

        $user = (string) $input->getArgument('lastfm-user');
        $from = $this->parseDate($input->getOption('from'));
        $to = $this->parseDate($input->getOption('to'));
        $tolerance = max(0, (int) $input->getOption('tolerance'));
        $dryRun = (bool) $input->getOption('dry-run');

        $io->section(sprintf(
            'Importing scrobbles for Last.fm user "%s"%s%s%s',
            $user,
            $from ? ' from ' . $from->format('Y-m-d') : '',
            $to ? ' to ' . $to->format('Y-m-d') : '',
            $dryRun ? ' [DRY-RUN]' : '',
        ));

        try {
            $report = $this->importer->import(
                apiKey: $apiKey,
                lastFmUser: $user,
                from: $from,
                to: $to,
                toleranceSeconds: $tolerance,
                dryRun: $dryRun,
                progress: function (int $f, int $i, int $d, int $u) use ($io): void {
                    $io->writeln(sprintf(
                        '  fetched=%d  inserted=%d  duplicates=%d  unmatched=%d',
                        $f,
                        $i,
                        $d,
                        $u,
                    ));
                },
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->newLine();
        $io->success(sprintf(
            '%s done. fetched=%d inserted=%d duplicates=%d unmatched=%d',
            $dryRun ? 'Dry-run' : 'Import',
            $report->fetched,
            $report->inserted,
            $report->duplicates,
            $report->unmatched,
        ));

        $this->renderUnmatched($io, $report, (string) $input->getOption('show-unmatched'));

        return Command::SUCCESS;
    }

    private function renderUnmatched(SymfonyStyle $io, \App\LastFm\ImportReport $report, string $showOption): void
    {
        if ($report->distinctUnmatched() === 0) {
            return;
        }

        $limit = strtolower($showOption) === 'all' ? null : max(0, (int) $showOption);
        if ($limit === 0) {
            return;
        }

        $rows = $report->unmatchedRanking($limit);
        $io->section(sprintf(
            'Unmatched scrobbles by play count (showing %d of %d distinct tracks, %d total scrobbles)',
            count($rows),
            $report->distinctUnmatched(),
            $report->unmatched,
        ));

        $tableRows = array_map(static fn (array $r) => [
            $r['count'],
            $r['artist'],
            $r['title'],
            $r['album'],
        ], $rows);
        $io->table(['Plays', 'Artist', 'Title', 'Album'], $tableRows);

        $io->note('These tracks were scrobbled on Last.fm but were not found in your Navidrome library (different artist/title or absent). Import will skip them until they are added/renamed.');
    }

    private function parseDate(?string $raw): ?\DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($raw);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException(sprintf('Invalid date "%s": %s', $raw, $e->getMessage()));
        }
    }
}
