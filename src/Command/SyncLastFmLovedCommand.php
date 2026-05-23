<?php

namespace App\Command;

use App\Entity\RunHistory;
use App\LastFm\LastFmFetcher;
use App\LastFm\LovedSyncReport;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sync the Last.fm loved-tracks list onto the local scrobbles table —
 * flips `loved=1` retroactively on every scrobble matching a loved
 * (artist, title) or MBID. Run after each `app:lastfm:fetch` (or in
 * a crontab) to keep /lastfm/stats heart counts honest.
 */
#[AsCommand(
    name: 'app:lastfm:loved:sync',
    description: 'Retro-flag scrobbles whose track is in the Last.fm loved list.',
)]
class SyncLastFmLovedCommand extends Command
{
    public function __construct(
        private readonly LastFmFetcher $fetcher,
        private readonly RunHistoryRecorder $recorder,
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
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Don\'t update the DB, just report what would change.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $user = (string) ($input->getArgument('user') ?: $this->defaultUser);
        $apiKey = (string) ($input->getOption('api-key') ?: $this->defaultApiKey);
        $dryRun = (bool) $input->getOption('dry-run');

        if ($user === '') {
            $io->error('No Last.fm user specified. Pass as argument or set LASTFM_USER env.');
            return Command::FAILURE;
        }
        if ($apiKey === '') {
            $io->error('No Last.fm API key. Pass --api-key or set LASTFM_API_KEY env.');
            return Command::FAILURE;
        }

        $io->writeln(sprintf('Sync des loved tracks pour <info>%s</info>%s…', $user, $dryRun ? ' [dry-run]' : ''));

        try {
            $report = $this->recorder->record(
                type: RunHistory::TYPE_LASTFM_LOVED_SYNC,
                reference: $user,
                label: sprintf('Last.fm loved sync %s%s', $user, $dryRun ? ' [dry-run]' : ''),
                action: fn () => $this->fetcher->syncLoved($apiKey, $user, $dryRun),
                extractMetrics: static fn (LovedSyncReport $r) => [
                    'fetched' => $r->fetched,
                    'matched' => $r->matched,
                    'unmatched' => $r->unmatched,
                    'updated_rows' => $r->updatedRows,
                    'dry_run' => $dryRun,
                ],
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            '%d loved tracks fetched ; %d matched, %d unmatched, %d scrobble row(s) flipped.',
            $report->fetched,
            $report->matched,
            $report->unmatched,
            $report->updatedRows,
        ));

        return Command::SUCCESS;
    }
}
