<?php

namespace App\Command;

use App\Entity\RunHistory;
use App\Entity\ScrobbleSync;
use App\Repository\LastFmMatchCacheRepository;
use App\Repository\ScrobbleSyncRepository;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Re-queue les scrobbles non-matchés : reset unmatched → pending (+ purge des
 * négatifs du cache de matching pour la cible navidrome), SANS lancer la
 * cascade. Un `app:scrobbles:sync-navidrome` / `rematch` ultérieur traitera
 * ensuite les lignes remises en attente, par lots.
 *
 * Bon marché et sûr : ne touche QUE la DB outils (scrobble_sync +
 * lastfm_match_cache), jamais la DB Navidrome — donc aucun arrêt de conteneur
 * ni backup nécessaire.
 */
#[AsCommand(
    name: 'app:scrobbles:requeue-unmatched',
    description: 'Re-queue (reset → pending) les scrobbles non-matchés, sans les traiter.',
)]
class RequeueUnmatchedCommand extends Command
{
    public function __construct(
        private readonly ScrobbleSyncRepository $syncRepo,
        private readonly LastFmMatchCacheRepository $matchCache,
        private readonly RunHistoryRecorder $recorder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('target', 't', InputOption::VALUE_REQUIRED, 'Target: navidrome or strawberry.', 'navidrome');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $target = (string) $input->getOption('target');

        if (!in_array($target, [ScrobbleSync::TARGET_NAVIDROME, ScrobbleSync::TARGET_STRAWBERRY], true)) {
            $io->error(sprintf('Invalid target "%s". Use "navidrome" or "strawberry".', $target));
            return Command::INVALID;
        }

        $type = $target === ScrobbleSync::TARGET_NAVIDROME
            ? RunHistory::TYPE_NAVIDROME_REQUEUE
            : RunHistory::TYPE_STRAWBERRY_REQUEUE;

        try {
            $requeued = $this->recorder->record(
                type: $type,
                reference: 'unmatched',
                label: sprintf('%s re-queue non-matchés', $target),
                action: function () use ($target): int {
                    // Purge des négatifs du cache de matching (navidrome
                    // uniquement — Strawberry n'a pas ce cache) pour que les
                    // lignes soient réellement ré-évaluées au prochain traitement.
                    if ($target === ScrobbleSync::TARGET_NAVIDROME) {
                        $this->matchCache->purgeUnmatchedNegatives($target);
                    }

                    return $this->syncRepo->resetUnmatchedToPending($target);
                },
                extractMetrics: static fn (int $n): array => ['requeued' => $n],
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf('%s : %d non-matché(s) remis en attente (pending).', ucfirst($target), $requeued));

        return Command::SUCCESS;
    }
}
