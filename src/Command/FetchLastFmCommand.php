<?php

namespace App\Command;

use App\Entity\RunHistory;
use App\LastFm\FetchReport;
use App\LastFm\FetchWindowResolver;
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
 * Fetch scrobbles from Last.fm and store them in the local `scrobbles` table.
 *
 * Window resolution is delegated to {@see FetchWindowResolver} so the CLI
 * and the web button (FetchLastFmMessageHandler) agree on what "by default"
 * means — 48h on a fresh install, smart date on subsequent runs.
 */
#[AsCommand(
    name: 'app:lastfm:fetch',
    description: 'Fetch Last.fm scrobble history into the local scrobbles table.',
)]
class FetchLastFmCommand extends Command
{
    public function __construct(
        private readonly LastFmFetcher $fetcher,
        private readonly RunHistoryRecorder $recorder,
        private readonly FetchWindowResolver $windowResolver,
        private readonly string $defaultApiKey,
        private readonly string $defaultUser,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('user', InputArgument::OPTIONAL, 'Last.fm username (defaults to LASTFM_USER env).')
            ->addOption('api-key', null, InputOption::VALUE_REQUIRED, 'Last.fm API key (defaults to LASTFM_API_KEY env).')
            ->addOption('date-min', null, InputOption::VALUE_REQUIRED, 'Fetch from this date (YYYY-MM-DD). Overrides smart date.')
            ->addOption('date-max', null, InputOption::VALUE_REQUIRED, 'Fetch until this date (YYYY-MM-DD).')
            ->addOption('max-scrobbles', null, InputOption::VALUE_REQUIRED, 'Stop after N scrobbles.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count without writing to DB.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $user = (string) ($input->getArgument('user') ?: $this->defaultUser);
        $apiKey = (string) ($input->getOption('api-key') ?: $this->defaultApiKey);
        $dryRun = (bool) $input->getOption('dry-run');
        $maxScrobbles = $input->getOption('max-scrobbles') !== null ? (int) $input->getOption('max-scrobbles') : null;

        if ($user === '') {
            $io->error('No Last.fm user specified. Pass as argument or set LASTFM_USER env.');
            return Command::FAILURE;
        }
        if ($apiKey === '') {
            $io->error('No Last.fm API key. Pass --api-key or set LASTFM_API_KEY env.');
            return Command::FAILURE;
        }

        $window = $this->windowResolver->resolve(
            $user,
            $input->getOption('date-min'),
            $input->getOption('date-max'),
        );
        $dateMin = $window['dateMin'];
        $dateMax = $window['dateMax'];
        $smartDate = $window['source'] !== 'explicit';

        $io->note(sprintf(
            'Fetch window: %s → %s (%s)',
            $dateMin->format('Y-m-d H:i:s'),
            $dateMax?->format('Y-m-d H:i:s') ?? 'now',
            match ($window['source']) {
                'smart' => 'smart date',
                'default' => sprintf('default %dh — no previous fetch on record', FetchWindowResolver::DEFAULT_WINDOW_HOURS),
                'explicit' => 'explicit',
            },
        ));

        $label = sprintf('Last.fm fetch %s%s', $user, $dryRun ? ' [dry-run]' : '');
        $now = new \DateTimeImmutable();

        try {
            $report = $this->recorder->record(
                type: RunHistory::TYPE_LASTFM_FETCH,
                reference: $user,
                label: $label,
                action: fn () => $this->fetcher->fetch(
                    apiKey: $apiKey,
                    lastFmUser: $user,
                    dateMin: $dateMin,
                    dateMax: $dateMax,
                    maxScrobbles: $maxScrobbles,
                    dryRun: $dryRun,
                    progress: function (int $f, int $i, int $d) use ($io): void {
                        $io->writeln(sprintf('  fetched=%d inserted=%d duplicates=%d', $f, $i, $d));
                    },
                ),
                extractMetrics: static fn (FetchReport $r) => [
                    'fetched' => $r->fetched,
                    'inserted' => $r->inserted,
                    'duplicates' => $r->duplicates,
                    'smart_date' => $smartDate,
                    'dry_run' => $dryRun,
                ],
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        // Update last_fetch only when the user did not pin an explicit window.
        if ($smartDate && !$dryRun && $report->fetched > 0) {
            $this->windowResolver->markFetchedAt($user, $now);
        }

        $io->success(sprintf(
            '%s done. fetched=%d inserted=%d duplicates=%d',
            $dryRun ? 'Dry-run' : 'Fetch',
            $report->fetched,
            $report->inserted,
            $report->duplicates,
        ));

        return Command::SUCCESS;
    }
}
