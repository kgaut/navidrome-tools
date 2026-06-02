<?php

namespace App\Command;

use App\Entity\ScrobbleSync;
use App\Navidrome\NavidromeRepository;
use App\Service\AliasGenerationOptions;
use App\Service\AliasGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Auto-generate high-confidence Last.fm → Navidrome aliases for unmatched
 * scrobbles, using MusicBrainz ids (artist / album) as ground truth plus a
 * tight Levenshtein title fallback. See {@see AliasGenerator} for the
 * per-strategy rules.
 *
 * Reads Navidrome read-only (no need to stop the container); writes only the
 * tools DB (alias + match-cache tables). Run `app:scrobbles:rematch` afterwards
 * to actually re-resolve the unmatched scrobbles through the new aliases.
 */
#[AsCommand(
    name: 'app:aliases:generate',
    description: 'Generate high-confidence aliases for unmatched scrobbles (MBID bridge + fuzzy).',
)]
class GenerateAliasesCommand extends Command
{
    public function __construct(
        private readonly AliasGenerator $generator,
        private readonly NavidromeRepository $navidrome,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview only — write nothing to the DB.')
            ->addOption('target', 't', InputOption::VALUE_REQUIRED, 'Unmatched set to scan (navidrome|strawberry).', 'navidrome')
            ->addOption('no-artist-mbid', null, InputOption::VALUE_NONE, 'Disable artist aliases via shared MusicBrainz artist id.')
            ->addOption('no-album-exact', null, InputOption::VALUE_NONE, 'Disable track aliases via album MBID + exact title.')
            ->addOption('no-album-fuzzy', null, InputOption::VALUE_NONE, 'Disable track aliases via album MBID + fuzzy title.')
            ->addOption('no-artist-fuzzy', null, InputOption::VALUE_NONE, 'Disable track aliases via owned-artist + fuzzy title.')
            ->addOption('album-fuzzy-distance', null, InputOption::VALUE_REQUIRED, 'Max Levenshtein distance for album-fuzzy titles.', '5')
            ->addOption('artist-fuzzy-distance', null, InputOption::VALUE_REQUIRED, 'Max Levenshtein distance for artist-fuzzy titles.', '2')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max unmatched couples to scan (0 = no limit).', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $target = (string) $input->getOption('target');
        if (!in_array($target, [ScrobbleSync::TARGET_NAVIDROME, ScrobbleSync::TARGET_STRAWBERRY], true)) {
            $io->error(sprintf('Invalid target "%s". Use "navidrome" or "strawberry".', $target));
            return Command::FAILURE;
        }

        $opts = new AliasGenerationOptions(
            target: $target,
            dryRun: (bool) $input->getOption('dry-run'),
            artistMbid: !$input->getOption('no-artist-mbid'),
            albumExact: !$input->getOption('no-album-exact'),
            albumFuzzy: !$input->getOption('no-album-fuzzy'),
            artistFuzzy: !$input->getOption('no-artist-fuzzy'),
            albumFuzzyMaxDistance: max(0, (int) $input->getOption('album-fuzzy-distance')),
            artistFuzzyMaxDistance: max(0, (int) $input->getOption('artist-fuzzy-distance')),
            limit: max(0, (int) $input->getOption('limit')),
        );

        $io->title($opts->dryRun ? 'Alias generation (dry-run)' : 'Alias generation');
        $io->progressStart();

        try {
            $report = $this->generator->generate($opts, function () use ($io): void {
                $io->progressAdvance();
            });
        } catch (\Throwable $e) {
            $io->newLine(2);
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
        $io->progressFinish();

        if ($report->samples !== []) {
            // Resolve track targets (media_file ids) to "artist — title" labels
            // so the preview is reviewable; artist aliases already carry a name.
            $trackTargetIds = [];
            foreach ($report->samples as $s) {
                if ($s['type'] === 'track') {
                    $trackTargetIds[] = $s['target'];
                }
            }
            $labels = $this->navidrome->getMediaFileLabels($trackTargetIds);

            $io->section('Sample of generated aliases');
            $io->table(
                ['type', 'strategy', 'source', '→ target', 'plays'],
                array_map(static function (array $s) use ($labels): array {
                    $target = $s['type'] === 'track' ? ($labels[$s['target']] ?? $s['target']) : $s['target'];
                    return [
                        $s['type'],
                        $s['strategy'],
                        mb_strimwidth($s['source'], 0, 44, '…'),
                        mb_strimwidth($target, 0, 44, '…'),
                        (string) $s['plays'],
                    ];
                }, $report->samples),
            );
        }

        $io->section('Summary');
        $io->definitionList(
            ['Artist aliases (mbid)' => sprintf('%d  (+%d plays, %d already aliased)', $report->artistAliasesCreated, $report->playsCoveredArtist, $report->artistAliasesSkipped)],
            ['Track album-mbid exact' => (string) $report->trackAlbumExact],
            ['Track album-mbid fuzzy' => (string) $report->trackAlbumFuzzy],
            ['Track artist fuzzy' => (string) $report->trackArtistFuzzy],
            ['Track aliases total' => sprintf('%d  (+%d plays)', $report->trackAliasesCreated(), $report->playsCoveredTrack)],
            ['Couples considered' => (string) $report->couplesConsidered],
            ['Already resolvable by rematch (skipped)' => (string) $report->cascadeResolvable],
            ['Ambiguous (skipped)' => (string) $report->trackAmbiguous],
            ['Already aliased (skipped)' => (string) $report->trackExistingSkipped],
        );

        $verb = $report->dryRun ? 'would create' : 'created';
        $io->success(sprintf(
            '%s %d aliases (%d artist + %d track) covering ~%d unmatched plays.%s',
            ucfirst($verb),
            $report->totalCreated(),
            $report->artistAliasesCreated,
            $report->trackAliasesCreated(),
            $report->playsCoveredArtist + $report->playsCoveredTrack,
            $report->dryRun ? ' Re-run without --dry-run to persist.' : ' Run app:scrobbles:rematch to apply them.',
        ));

        return Command::SUCCESS;
    }
}
