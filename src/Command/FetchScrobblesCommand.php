<?php

namespace App\Command;

use App\Scrobble\ScrobbleFetchRunner;
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
        private readonly ScrobbleFetchRunner $runner,
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
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $source = $this->runner->source();
            $report = $this->runner->run(
                $input->getOption('date-min'),
                $input->getOption('date-max'),
                $input->getOption('max-scrobbles') !== null ? (int) $input->getOption('max-scrobbles') : null,
                $dryRun,
                static function (array $window) use ($io, $source): void {
                    $io->note(sprintf(
                        'Source %s (%s) — fenêtre : %s → %s (%s)',
                        $source->name(),
                        $source->user(),
                        $window['dateMin']->format('Y-m-d H:i:s'),
                        $window['dateMax']?->format('Y-m-d H:i:s') ?? 'maintenant',
                        $window['source'],
                    ));
                },
                static function (int $f, int $i, int $d) use ($io): void {
                    $io->writeln(sprintf('  fetched=%d inserted=%d duplicates=%d', $f, $i, $d));
                },
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
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
