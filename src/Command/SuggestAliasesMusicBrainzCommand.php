<?php

namespace App\Command;

use App\Entity\RunHistory;
use App\Entity\ScrobbleSync;
use App\Service\MusicBrainzAliasReport;
use App\Service\MusicBrainzAliasSuggester;
use App\Service\MusicBrainzAliasSuggestion;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Query MusicBrainz for unmatched-artist aliases and bridge them to library
 * artists. Complements {@see GenerateAliasesCommand} (offline, MBID-only) with
 * an online lookup that catches cases where Last.fm never sent an MBID or
 * where the artist spelling differs from any owned artist by more than
 * normalization tolerates.
 *
 * Default mode applies high-confidence (unique-library-match) aliases right
 * away. Ambiguous candidates are skipped silently unless `--interactive` is
 * given, in which case the user picks the target. `--dry-run` previews
 * everything without writing.
 *
 * Reads Navidrome read-only (no need to stop the container); writes only the
 * tools DB. Run `app:scrobbles:requeue-unmatched` then `app:scrobbles:rematch`
 * afterwards to re-resolve the unmatched scrobbles through the new aliases.
 */
#[AsCommand(
    name: 'app:aliases:musicbrainz',
    description: 'Suggest artist aliases for unmatched scrobbles via MusicBrainz online lookup.',
)]
class SuggestAliasesMusicBrainzCommand extends Command
{
    /** MB asks 1 req/s per UA. 1100 ms keeps a small safety margin. */
    private const DEFAULT_RATE_LIMIT_MS = 1100;

    public function __construct(
        private readonly MusicBrainzAliasSuggester $suggester,
        private readonly RunHistoryRecorder $recorder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('target', 't', InputOption::VALUE_REQUIRED, 'Unmatched set to scan (navidrome|strawberry).', 'navidrome')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview only — write nothing.')
            ->addOption('interactive', 'i', InputOption::VALUE_NONE, 'Prompt for ambiguous candidates.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max artists to query (0 = no limit).', '0')
            ->addOption('rate-limit-ms', null, InputOption::VALUE_REQUIRED, 'Throttle between MB requests, in ms (≥1000 advised).', (string) self::DEFAULT_RATE_LIMIT_MS);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $target = (string) $input->getOption('target');
        if (!in_array($target, [ScrobbleSync::TARGET_NAVIDROME, ScrobbleSync::TARGET_STRAWBERRY], true)) {
            $io->error(sprintf('Invalid target "%s". Use "navidrome" or "strawberry".', $target));
            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $interactive = (bool) $input->getOption('interactive');
        $limit = max(0, (int) $input->getOption('limit'));
        $rateLimitMs = max(0, (int) $input->getOption('rate-limit-ms'));

        $label = sprintf('MusicBrainz alias suggest [%s%s%s]', $target, $dryRun ? ' dry-run' : '', $interactive ? ' interactive' : '');

        $io->title($label);
        if ($rateLimitMs < 1000) {
            $io->warning('Rate limit below 1000 ms — MusicBrainz may reject requests (503).');
        }

        $beforeQuery = static function (string $artist) use ($io, $rateLimitMs): void {
            if ($rateLimitMs > 0) {
                usleep($rateLimitMs * 1000);
            }
            $io->writeln(sprintf('  → query MB: %s', $artist), OutputInterface::VERBOSITY_VERBOSE);
        };

        $confirm = $interactive
            ? fn (MusicBrainzAliasSuggestion $s): ?string => $this->promptAmbiguous($io, $s)
            : null;

        try {
            $report = $this->recorder->record(
                type: RunHistory::TYPE_NAVIDROME_ALIAS_MUSICBRAINZ,
                reference: $target,
                label: $label,
                action: fn () => $this->suggester->suggest($target, $dryRun, $limit, $beforeQuery, $confirm),
                extractMetrics: static fn (MusicBrainzAliasReport $r): array => [
                    'queried' => $r->artistsQueried,
                    'created' => $r->aliasesCreated,
                    'ambiguous' => $r->ambiguous,
                    'no_match' => $r->noMatch,
                    'mb_errors' => $r->mbErrors,
                    'plays_covered' => $r->playsCovered,
                ],
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $this->printReport($io, $report);

        return Command::SUCCESS;
    }

    private function promptAmbiguous(SymfonyStyle $io, MusicBrainzAliasSuggestion $s): ?string
    {
        $io->section(sprintf('Ambiguous: %s (%d plays)', $s->sourceArtist, $s->plays));
        foreach ($s->evidence as $e) {
            $io->writeln(sprintf(
                '  · %s (mbid=%s, score=%d, matched via "%s")',
                $e['name'],
                $e['mbid'],
                $e['score'],
                $e['matched_via'] ?? '—',
            ));
        }
        $choices = [...$s->targetCandidates, 'skip'];
        /** @var string $picked */
        $picked = $io->choice('Pick the library artist to alias to', $choices, 'skip');

        return $picked === 'skip' ? null : $picked;
    }

    private function printReport(SymfonyStyle $io, MusicBrainzAliasReport $report): void
    {
        if ($report->samples !== []) {
            $io->section('Sample suggestions');
            $rows = [];
            foreach ($report->samples as $s) {
                $rows[] = [
                    $s->kind,
                    mb_strimwidth($s->sourceArtist, 0, 32, '…'),
                    mb_strimwidth(implode(' | ', $s->targetCandidates) ?: '—', 0, 38, '…'),
                    (string) $s->plays,
                ];
            }
            $io->table(['kind', 'source', 'library target(s)', 'plays'], $rows);
        }

        $io->section('Summary');
        $io->definitionList(
            ['Artists considered' => (string) $report->artistsConsidered],
            ['Already aliased (skipped)' => (string) $report->skippedAlreadyAliased],
            ['Already owned by lib (skipped)' => (string) $report->skippedAlreadyOwned],
            ['Queried MusicBrainz' => (string) $report->artistsQueried],
            ['Unique-match aliases' => sprintf('%d  (+%d plays)', $report->aliasesCreated, $report->playsCovered)],
            ['Ambiguous (skipped)' => (string) $report->ambiguous],
            ['No library match' => (string) $report->noMatch],
            ['MusicBrainz errors' => (string) $report->mbErrors],
        );

        $verb = $report->dryRun ? 'would create' : 'created';
        $io->success(sprintf(
            '%s %d aliases covering ~%d unmatched plays.%s',
            ucfirst($verb),
            $report->aliasesCreated,
            $report->playsCovered,
            $report->dryRun ? ' Re-run without --dry-run to persist.' : ' Run app:scrobbles:requeue-unmatched then app:scrobbles:rematch to apply them.',
        ));
    }
}
