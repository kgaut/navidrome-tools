<?php

namespace App\Command;

use App\Entity\RunHistory;
use App\LastFm\LovesSyncReport;
use App\Loves\ActiveLovesProvider;
use App\Service\LovesSyncService;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Navidrome starred → loved on the active source (SCROBBLE_SOURCE), issue
 * #310. Last.fm needs a session key (`app:lastfm:auth`); ListenBrainz needs
 * LISTENBRAINZ_TOKEN and a recording MBID (tag, or strict metadata lookup).
 */
#[AsCommand(
    name: 'app:loves:push',
    description: 'Love on the active source (SCROBBLE_SOURCE) the tracks starred in Navidrome.',
)]
class LovesPushCommand extends Command
{
    public function __construct(
        private readonly ActiveLovesProvider $providers,
        private readonly LovesSyncService $service,
        private readonly RunHistoryRecorder $recorder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count what would be loved without writing (tracks are identified, read-only).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $provider = $this->providers->get();
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
        if (!$provider->canWrite()) {
            $io->error(sprintf(
                'Écriture impossible sur « %s » : %s.',
                $provider->name(),
                $provider->name() === 'listenbrainz' ? 'LISTENBRAINZ_USER et LISTENBRAINZ_TOKEN requis' : 'clé, secret et session Last.fm requis (app:lastfm:auth)',
            ));
            return Command::FAILURE;
        }

        try {
            $report = $this->recorder->record(
                type: RunHistory::TYPE_LOVES_PUSH,
                reference: $provider->user(),
                label: sprintf('Loves Navidrome→%s %s%s', $provider->name(), $provider->user(), $dryRun ? ' [dry-run]' : ''),
                action: fn () => $this->service->pushFromNavidrome($provider, $dryRun, function (int $c, int $a, int $u) use ($io): void {
                    $io->writeln(sprintf('  considered=%d applied=%d unmatched=%d', $c, $a, $u));
                }),
                extractMetrics: static fn (LovesSyncReport $r) => self::metrics($r, $provider->name(), $dryRun),
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success(self::summary($report, $dryRun));

        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    public static function metrics(LovesSyncReport $r, string $source, bool $dryRun): array
    {
        return [
            'source' => $source,
            'considered' => $r->considered,
            'applied' => $r->applied,
            'already_in_sync' => $r->alreadyInSync,
            'unmatched' => $r->unmatched,
            'errors' => $r->errors,
            'dry_run' => $dryRun,
        ];
    }

    public static function summary(LovesSyncReport $r, bool $dryRun): string
    {
        return sprintf(
            '%s : considered=%d applied=%d already_in_sync=%d unmatched=%d errors=%d',
            $dryRun ? 'Dry-run' : 'Done',
            $r->considered,
            $r->applied,
            $r->alreadyInSync,
            $r->unmatched,
            $r->errors,
        );
    }
}
