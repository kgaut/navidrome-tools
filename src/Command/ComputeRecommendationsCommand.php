<?php

namespace App\Command;

use App\Entity\RunHistory;
use App\Recommendation\RecommendationEngine;
use App\Recommendation\RecommendationResult;
use App\Recommendation\RecommendationStore;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Compute the artist recommendations snapshot from my listening.
 *
 *   app:recommendations:compute [--limit=50] [--dry-run]
 *
 * `--dry-run` prints the ranked list without saving the snapshot or touching
 * Lidarr (adding artists stays a manual, per-artist action in the UI).
 */
#[AsCommand(
    name: 'app:recommendations:compute',
    description: 'Compute personalized artist recommendations (Last.fm / ListenBrainz) for the review page.',
)]
class ComputeRecommendationsCommand extends Command
{
    /** MusicBrainz rate-limits at 1 req/s per UA — stay just under. */
    private const MB_THROTTLE_MICROSECONDS = 1_100_000;

    public function __construct(
        private readonly RecommendationEngine $engine,
        private readonly RecommendationStore $store,
        private readonly RunHistoryRecorder $recorder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of recommendations to keep.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the ranked list without saving the snapshot.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $limitOpt = $input->getOption('limit');
        $limit = is_numeric($limitOpt) ? max(1, (int) $limitOpt) : null;
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title($dryRun ? 'Recommandations [dry-run]' : 'Recommandations');

        $throttle = static function (string $name): void {
            usleep(self::MB_THROTTLE_MICROSECONDS);
        };

        try {
            if ($dryRun) {
                $result = $this->engine->compute($limit, $throttle);
            } else {
                $result = $this->recorder->record(
                    type: RunHistory::TYPE_RECOMMENDATIONS,
                    reference: 'compute',
                    label: 'Calcul des recommandations d\'artistes (CLI)',
                    action: function () use ($limit, $throttle): RecommendationResult {
                        $r = $this->engine->compute($limit, $throttle);
                        $this->store->save($r, new \DateTimeImmutable());

                        return $r;
                    },
                    extractMetrics: static fn (RecommendationResult $r): array => ['recommandations' => $r->count()],
                );
            }
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $this->printResult($io, $result);

        return Command::SUCCESS;
    }

    private function printResult(SymfonyStyle $io, RecommendationResult $result): void
    {
        if ($result->count() === 0) {
            $io->warning('Aucune recommandation (vérifiez LASTFM_API_KEY / LISTENBRAINZ_USER et votre historique).');
            return;
        }

        $rows = [];
        foreach ($result->recommendations as $rank => $r) {
            $rows[] = [
                $rank + 1,
                $r->name,
                $r->mbid ?? '—',
                number_format($r->score, 1),
                implode(', ', $r->sources),
                implode(', ', array_slice($r->seeds, 0, 3)),
            ];
        }
        $io->table(['#', 'artiste', 'mbid', 'score', 'sources', 'seeds'], $rows);
        $io->success(sprintf(
            '%d recommandation(s) — %d seeds, %d candidats, %d lookups MBID.',
            $result->count(),
            $result->seedCount,
            $result->rawCandidates,
            $result->mbidLookups,
        ));
    }
}
