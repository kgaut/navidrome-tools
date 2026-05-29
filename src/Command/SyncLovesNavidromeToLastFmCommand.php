<?php

namespace App\Command;

use App\Entity\RunHistory;
use App\LastFm\LovesSyncReport;
use App\Service\LastFmSessionService;
use App\Service\LovesSyncService;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Push every Navidrome starred media_file to Last.fm `track.love`. One-way ;
 * never unloves. Requires `app:lastfm:auth` to have populated a session key
 * for the target user (no password leaves the cache).
 *
 * Pure API caller, no write to the Navidrome DB — Navidrome can stay up.
 */
#[AsCommand(
    name: 'app:loves:navidrome-to-lastfm',
    description: 'Propagate Navidrome starred tracks to Last.fm loved.',
)]
class SyncLovesNavidromeToLastFmCommand extends Command
{
    public function __construct(
        private readonly LovesSyncService $service,
        private readonly LastFmSessionService $sessions,
        private readonly RunHistoryRecorder $recorder,
        private readonly string $defaultApiKey,
        private readonly string $defaultApiSecret,
        private readonly string $defaultUser,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('user', InputArgument::OPTIONAL, 'Last.fm username (defaults to LASTFM_USER).')
            ->addOption('api-key', null, InputOption::VALUE_REQUIRED, 'Last.fm API key (defaults to LASTFM_API_KEY).')
            ->addOption('api-secret', null, InputOption::VALUE_REQUIRED, 'Last.fm API secret (defaults to LASTFM_API_SECRET).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be loved without calling Last.fm.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $user = (string) ($input->getArgument('user') ?: $this->defaultUser);
        $apiKey = (string) ($input->getOption('api-key') ?: $this->defaultApiKey);
        $apiSecret = (string) ($input->getOption('api-secret') ?: $this->defaultApiSecret);
        $dryRun = (bool) $input->getOption('dry-run');

        if ($user === '' || $apiKey === '' || $apiSecret === '') {
            $io->error('LASTFM_USER, LASTFM_API_KEY and LASTFM_API_SECRET are required (env or flags).');
            return Command::FAILURE;
        }

        $sk = $this->sessions->get($user);
        if ($sk === null) {
            $io->error(sprintf(
                'No Last.fm session key stored for %s. Run `php bin/console app:lastfm:auth` first.',
                $user,
            ));
            return Command::FAILURE;
        }

        $label = sprintf('Loves Navidrome→Last.fm %s%s', $user, $dryRun ? ' [dry-run]' : '');

        try {
            $report = $this->recorder->record(
                type: RunHistory::TYPE_LOVES_NAVIDROME_TO_LASTFM,
                reference: $user,
                label: $label,
                action: fn () => $this->service->pushNavidromeToLastFm(
                    $apiKey,
                    $apiSecret,
                    $sk,
                    $user,
                    $dryRun,
                    function (int $c, int $a, int $u) use ($io): void {
                        $io->writeln(sprintf('  considered=%d applied=%d unmatched=%d', $c, $a, $u));
                    },
                ),
                extractMetrics: static fn (LovesSyncReport $r) => [
                    'considered' => $r->considered,
                    'applied' => $r->applied,
                    'already_in_sync' => $r->alreadyInSync,
                    'unmatched' => $r->unmatched,
                    'errors' => $r->errors,
                    'dry_run' => $dryRun,
                ],
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->newLine();
        $io->success(sprintf(
            '%s : considered=%d applied=%d already_in_sync=%d unmatched=%d errors=%d',
            $dryRun ? 'Dry-run' : 'Done',
            $report->considered,
            $report->applied,
            $report->alreadyInSync,
            $report->unmatched,
            $report->errors,
        ));

        return Command::SUCCESS;
    }
}
