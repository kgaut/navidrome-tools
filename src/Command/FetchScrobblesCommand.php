<?php

namespace App\Command;

use App\Entity\RunHistory;
use App\LastFm\FetchReport;
use App\Scrobble\ActiveScrobbleSource;
use App\Scrobble\ListenBrainzScrobbleSource;
use App\Scrobble\ScrobbleFetcher;
use App\Scrobble\SourceFetchWindowResolver;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fetch listens from the source selected by `SCROBBLE_SOURCE` (issue #310)
 * into the local `scrobbles` table. With `lastfm` it behaves like
 * `app:lastfm:fetch`; with `listenbrainz`, the first run starts right after
 * the most recent listen already stored (raccord).
 */
#[AsCommand(
    name: 'app:scrobbles:fetch',
    description: 'Fetch listens from the active source (SCROBBLE_SOURCE) into the local scrobbles table.',
)]
class FetchScrobblesCommand extends Command
{
    public function __construct(
        private readonly ActiveScrobbleSource $activeSource,
        private readonly ScrobbleFetcher $fetcher,
        private readonly SourceFetchWindowResolver $windowResolver,
        private readonly RunHistoryRecorder $recorder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('date-min', null, InputOption::VALUE_REQUIRED, 'Fetch from this date (YYYY-MM-DD). Overrides smart date and raccord.')
            ->addOption('date-max', null, InputOption::VALUE_REQUIRED, 'Fetch until this date (YYYY-MM-DD).')
            ->addOption('max-scrobbles', null, InputOption::VALUE_REQUIRED, 'Stop after N listens (0 = no limit).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count without writing to DB.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $source = $this->activeSource->get();
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
        if (!$source->isConfigured()) {
            $io->error(sprintf('Source « %s » non configurée (utilisateur ou clé manquants).', $source->name()));
            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $maxScrobbles = $input->getOption('max-scrobbles') !== null ? (int) $input->getOption('max-scrobbles') : null;
        $window = $this->windowResolver->resolve($source, $input->getOption('date-min'), $input->getOption('date-max'));
        $smartDate = $window['source'] !== 'explicit';

        $io->note(sprintf(
            'Source %s (%s) — fenêtre : %s → %s (%s)',
            $source->name(),
            $source->user(),
            $window['dateMin']->format('Y-m-d H:i:s'),
            $window['dateMax']?->format('Y-m-d H:i:s') ?? 'maintenant',
            $window['source'],
        ));

        $now = new \DateTimeImmutable();
        $type = $source->name() === ListenBrainzScrobbleSource::NAME ? RunHistory::TYPE_LISTENBRAINZ_FETCH : RunHistory::TYPE_LASTFM_FETCH;

        try {
            $report = $this->recorder->record(
                type: $type,
                reference: $source->user(),
                label: sprintf('%s fetch %s%s', $source->name(), $source->user(), $dryRun ? ' [dry-run]' : ''),
                action: fn () => $this->fetcher->fetch(
                    $source,
                    $window['dateMin'],
                    $window['dateMax'],
                    $maxScrobbles,
                    $dryRun,
                    function (int $f, int $i, int $d) use ($io): void {
                        $io->writeln(sprintf('  fetched=%d inserted=%d duplicates=%d', $f, $i, $d));
                    },
                ),
                extractMetrics: static fn (FetchReport $r) => [
                    'source' => $source->name(),
                    'fetched' => $r->fetched,
                    'inserted' => $r->inserted,
                    'duplicates' => $r->duplicates,
                    'window' => $window['source'],
                    'dry_run' => $dryRun,
                ],
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        // Record the fetch only for computed windows. A raccord that found
        // nothing new still records it: the next run then uses smart date.
        if ($smartDate && !$dryRun && ($report->fetched > 0 || $window['source'] === 'raccord')) {
            $this->windowResolver->markFetchedAt($source, $now);
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
