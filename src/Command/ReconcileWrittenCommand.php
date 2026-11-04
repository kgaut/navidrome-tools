<?php

namespace App\Command;

use App\Entity\RunHistory;
use App\Service\RunHistoryRecorder;
use App\Service\WrittenPlaysReconciler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Re-aligns data.db with navidrome.db after Navidrome lost writes, e.g. a
 * restore of an older backup (issue #298). Navidrome is read only.
 */
#[AsCommand(
    name: 'app:scrobbles:reconcile-written',
    description: 'Re-align plays data.db holds as written with what navidrome.db actually holds (after a restore).',
)]
class ReconcileWrittenCommand extends Command
{
    public function __construct(
        private readonly WrittenPlaysReconciler $reconciler,
        private readonly RunHistoryRecorder $recorder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count and show samples, write nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $report = $dryRun
            ? $this->reconciler->run(true)
            : $this->recorder->record(
                type: RunHistory::TYPE_NAVIDROME_RECONCILE,
                reference: 'matched',
                label: 'Navidrome reconcile written plays',
                action: fn () => $this->reconciler->run(false),
                extractMetrics: static fn (array $r): array => array_diff_key($r, ['samples' => true]),
            );

        $io->table(
            ['Issue', 'Artiste', 'Titre', 'Écoute (UTC)', 'Cible enregistrée', 'Morceau dans Navidrome'],
            array_map(static fn (array $s) => array_values($s), $report['samples']),
        );
        $io->writeln(sprintf(
            'examinées=%d en accord=%d %s=%d %s=%d ambiguës (laissées)=%d',
            $report['examined'],
            $report['in_line'],
            $dryRun ? 'cible à corriger' : 'cible corrigée',
            $report['retargeted'],
            $dryRun ? 'à remettre en pending' : 'remises en pending',
            $report['requeued'],
            $report['ambiguous'],
        ));
        $io->success($dryRun ? 'Dry-run : rien n\'est écrit.' : 'data.db réaligné ; le prochain sync réécrit les écoutes remises en pending.');

        return Command::SUCCESS;
    }
}
