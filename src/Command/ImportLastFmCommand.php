<?php

namespace App\Command;

use App\Entity\RunHistory;
use App\LastFm\FetchReport;
use App\LastFm\LastFmFetcher;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fetch a Last.fm scrobble history into the local lastfm_import_buffer
 * table. Does NOT touch the Navidrome DB — Navidrome can stay up.
 *
 * To match the buffered scrobbles and insert them into Navidrome, run
 * `app:lastfm:process` afterwards (with Navidrome stopped).
 */
#[AsCommand(
    name: 'app:lastfm:import',
    description: 'Fetch a Last.fm scrobble history into the local buffer (no Navidrome write).',
)]
class ImportLastFmCommand extends Command
{
    public function __construct(
        private readonly LastFmFetcher $fetcher,
        private readonly RunHistoryRecorder $recorder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'lastfm-user',
                InputArgument::OPTIONAL,
                'Last.fm username whose scrobbles you want to fetch. '
                . 'Defaults to the LASTFM_USER env var when omitted.',
            )
            ->addOption(
                'api-key',
                null,
                InputOption::VALUE_REQUIRED,
                'Last.fm API key (defaults to LASTFM_API_KEY env var).',
            )
            ->addOption(
                'date-min',
                null,
                InputOption::VALUE_REQUIRED,
                'Only fetch scrobbles on or after this date (YYYY-MM-DD or ISO).',
            )
            ->addOption(
                'date-max',
                null,
                InputOption::VALUE_REQUIRED,
                'Only fetch scrobbles before this date (YYYY-MM-DD or ISO).',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Stream the Last.fm history without writing to the buffer (useful to validate API connectivity).',
            )
            ->addOption(
                'max-scrobbles',
                null,
                InputOption::VALUE_REQUIRED,
                'Stop after fetching N scrobbles (safety cap). Omit for no cap.',
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

        $user = (string) ($input->getArgument('lastfm-user') ?? $_ENV['LASTFM_USER'] ?? getenv('LASTFM_USER') ?: '');
        if ($user === '') {
            $io->error('Provide a Last.fm username as the first argument or via the LASTFM_USER env var.');

            return Command::FAILURE;
        }
        $dateMin = $this->parseDate($input->getOption('date-min'));
        $dateMax = $this->parseDate($input->getOption('date-max'));
        $dryRun = (bool) $input->getOption('dry-run');

        $io->section(sprintf(
            'Fetching scrobbles for Last.fm user "%s"%s%s%s',
            $user,
            $dateMin ? ' from ' . $dateMin->format('Y-m-d') : '',
            $dateMax ? ' to ' . $dateMax->format('Y-m-d') : '',
            $dryRun ? ' [DRY-RUN]' : '',
        ));

        try {
            $maxScrobbles = $input->getOption('max-scrobbles');
            $maxScrobblesInt = $maxScrobbles !== null ? max(1, (int) $maxScrobbles) : null;

            $report = $this->recorder->record(
                type: RunHistory::TYPE_LASTFM_FETCH,
                reference: $user,
                label: 'Last.fm fetch — ' . $user . ($dryRun ? ' [dry-run]' : ''),
                action: fn (RunHistory $entry) => $this->fetcher->fetch(
                    apiKey: $apiKey,
                    lastFmUser: $user,
                    dateMin: $dateMin,
                    dateMax: $dateMax,
                    maxScrobbles: $maxScrobblesInt,
                    dryRun: $dryRun,
                    progress: function (int $f, int $b, int $a, ?\DateTimeImmutable $batchFirst, ?\DateTimeImmutable $batchLast) use ($io): void {
                        $range = '';
                        if ($batchFirst !== null && $batchLast !== null) {
                            $from = min($batchFirst, $batchLast);
                            $to = max($batchFirst, $batchLast);
                            $range = $from == $to
                                ? sprintf('  batch=%s', $from->format('Y-m-d H:i'))
                                : sprintf('  batch=%s → %s', $from->format('Y-m-d H:i'), $to->format('Y-m-d H:i'));
                        }
                        $io->writeln(sprintf(
                            '  fetched=%d  buffered=%d  already_buffered=%d%s',
                            $f,
                            $b,
                            $a,
                            $range,
                        ));
                    },
                ),
                extractMetrics: static fn (FetchReport $r) => [
                    'fetched' => $r->fetched,
                    'buffered' => $r->buffered,
                    'already_buffered' => $r->alreadyBuffered,
                    'dry_run' => $dryRun,
                    'date_min' => $dateMin?->format('Y-m-d'),
                    'date_max' => $dateMax?->format('Y-m-d'),
                ],
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->newLine();
        $io->success(sprintf(
            '%s done. fetched=%d buffered=%d already_buffered=%d',
            $dryRun ? 'Dry-run' : 'Fetch',
            $report->fetched,
            $report->buffered,
            $report->alreadyBuffered,
        ));

        if ($report->buffered > 0) {
            $io->note('Run `app:lastfm:process` (Navidrome must be stopped) to match buffered scrobbles and insert them into Navidrome.');
        }

        return Command::SUCCESS;
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
