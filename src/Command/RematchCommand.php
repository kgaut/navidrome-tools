<?php

namespace App\Command;

use App\Docker\NavidromeContainerException;
use App\Docker\NavidromeContainerManager;
use App\Entity\RunHistory;
use App\Entity\ScrobbleSync;
use App\Navidrome\NavidromeSyncReport;
use App\Navidrome\NavidromeSyncService;
use App\Service\RunHistoryRecorder;
use App\Strawberry\StrawberrySyncReport;
use App\Strawberry\StrawberrySyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Re-attempt matching for the scrobbles left PENDING of a given target.
 *
 * Ne (re-)remet PLUS les non-matchés en attente : c'est désormais le rôle de
 * `app:scrobbles:requeue-unmatched` (reset unmatched → pending + purge des
 * négatifs du cache). Cette commande ne fait que RE-JOUER la cascade sur les
 * lignes déjà en attente — non destructive, relançable sans risque.
 *
 * Flux type : `app:scrobbles:requeue-unmatched` (une fois, après avoir ajouté
 * des morceaux / créé des alias / déployé un matcher amélioré) puis `rematch`
 * (autant de fois que nécessaire, éventuellement par lots via --limit).
 */
#[AsCommand(
    name: 'app:scrobbles:rematch',
    description: 'Re-attempt matching for unmatched scrobbles (navidrome or strawberry).',
)]
class RematchCommand extends Command
{
    public function __construct(
        private readonly NavidromeSyncService $navidromeSync,
        private readonly StrawberrySyncService $strawberrySync,
        private readonly RunHistoryRecorder $recorder,
        private readonly NavidromeContainerManager $container,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('target', 't', InputOption::VALUE_REQUIRED, 'Target: navidrome or strawberry.', 'navidrome')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Match pending rows without writing.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max rows to process (0 = no limit).', '0')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Bypass Navidrome container pre-flight.')
            ->addOption('auto-stop', null, InputOption::VALUE_NONE, 'Auto-stop Navidrome (only relevant for navidrome target).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $target = (string) $input->getOption('target');
        $dryRun = (bool) $input->getOption('dry-run');
        $limit = max(0, (int) $input->getOption('limit'));
        $force = (bool) $input->getOption('force');
        $autoStop = (bool) $input->getOption('auto-stop');

        if (!in_array($target, [ScrobbleSync::TARGET_NAVIDROME, ScrobbleSync::TARGET_STRAWBERRY], true)) {
            $io->error(sprintf('Invalid target "%s". Use "navidrome" or "strawberry".', $target));
            return Command::FAILURE;
        }

        if ($target === ScrobbleSync::TARGET_NAVIDROME && !$dryRun && !$autoStop) {
            try {
                $this->container->assertSafeToWrite($force);
            } catch (NavidromeContainerException $e) {
                $io->error($e->getMessage());
                return Command::FAILURE;
            }
        }

        $label = sprintf('%s rematch%s', $target, $dryRun ? ' [dry-run]' : '');
        $type = $target === ScrobbleSync::TARGET_NAVIDROME
            ? RunHistory::TYPE_NAVIDROME_REMATCH
            : RunHistory::TYPE_STRAWBERRY_REMATCH;

        try {
            $runProcess = fn () => $this->recorder->record(
                type: $type,
                reference: 'unmatched',
                label: $label,
                action: function (RunHistory $entry) use ($target, $limit, $dryRun): NavidromeSyncReport|StrawberrySyncReport {
                    // Ne reset PLUS les non-matchés ni ne purge le cache : on
                    // ne fait que ré-jouer la cascade sur les lignes déjà en
                    // attente. Pour re-queuer les non-matchés,
                    // `app:scrobbles:requeue-unmatched`.
                    if ($target === ScrobbleSync::TARGET_NAVIDROME) {
                        return $this->navidromeSync->process(limit: $limit, dryRun: $dryRun, run: $entry);
                    }
                    return $this->strawberrySync->process(limit: $limit, dryRun: $dryRun, run: $entry);
                },
                extractMetrics: static function (NavidromeSyncReport|StrawberrySyncReport $r): array {
                    if ($r instanceof NavidromeSyncReport) {
                        return [
                            'considered' => $r->considered,
                            'matched' => $r->matched,
                            'duplicates' => $r->duplicates,
                            'unmatched' => $r->unmatched,
                            'api_errors' => $r->apiErrors,
                        ];
                    }
                    return [
                        'considered' => $r->considered,
                        'matched' => $r->matched,
                        'unmatched' => $r->unmatched,
                    ];
                },
            );

            $report = ($target === ScrobbleSync::TARGET_NAVIDROME && $autoStop && !$dryRun)
                ? $this->container->runWithNavidromeStopped($runProcess)
                : $runProcess();
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $matched = $report instanceof NavidromeSyncReport ? $report->matched : $report->matched;
        $unmatched = $report instanceof NavidromeSyncReport ? $report->unmatched : $report->unmatched;

        $io->success(sprintf(
            '%s rematch done. matched=%d unmatched=%d',
            ucfirst($target),
            $matched,
            $unmatched,
        ));

        if ($report instanceof NavidromeSyncReport && $report->apiErrors > 0) {
            $io->warning(sprintf(
                '%d scrobble(s) ignoré(s) sur erreur API Last.fm (laissés en pending, relancez pour réessayer).%s',
                $report->apiErrors,
                $report->abortedOnApiErrors
                    ? ' Run interrompu : trop d\'erreurs consécutives (Last.fm indisponible ?).'
                    : '',
            ));
        }

        return Command::SUCCESS;
    }
}
