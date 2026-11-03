<?php

namespace App\Command;

use App\Entity\RunHistory;
use App\Service\RunHistoryRecorder;
use App\Service\TargetIdRepairer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Re-points scrobble_sync.target_id at the track Navidrome holds each written
 * play under, when the recorded track left the library (issue #291).
 * Navidrome is read only; data.db is written — no container stop needed.
 */
#[AsCommand(
    name: 'app:scrobbles:repair-targets',
    description: 'Re-point written scrobbles whose recorded Navidrome track no longer exists at the track Navidrome holds the play under.',
)]
class RepairTargetsCommand extends Command
{
    public function __construct(
        private readonly TargetIdRepairer $repairer,
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
            ? $this->repairer->run(true)
            : $this->recorder->record(
                type: RunHistory::TYPE_NAVIDROME_REPAIR_TARGETS,
                reference: 'matched',
                label: 'Navidrome repair targets',
                action: fn () => $this->repairer->run(false),
                extractMetrics: static fn (array $r): array => array_diff_key($r, ['samples' => true]),
            );

        $io->table(
            ['Artiste', 'Titre scrobblé', 'Ancienne cible', 'Nouvelle cible', 'Titre Navidrome'],
            array_map(static fn (array $s) => array_values($s), $report['samples']),
        );
        $io->writeln(sprintf(
            'examinées=%d cible disparue=%d %s=%d sans écoute Navidrome à la seconde=%d ambiguës=%d titre différent (alias ?)=%d',
            $report['examined'],
            $report['stale'],
            $dryRun ? 'réparables' : 'réparées',
            $report['repaired'],
            $report['not_found'],
            $report['ambiguous'],
            $report['title_mismatch'],
        ));
        $io->success($dryRun ? 'Dry-run : rien n\'est écrit.' : 'Cibles réparées dans data.db.');

        return Command::SUCCESS;
    }
}
