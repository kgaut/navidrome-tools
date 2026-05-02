<?php

namespace App\Command;

use App\Entity\LastFmImportTrack;
use App\Entity\RunHistory;
use App\LastFm\ImportReport;
use App\LastFm\LastFmImporter;
use App\LastFm\LastFmScrobble;
use App\Service\RunHistoryRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:lastfm:import',
    description: 'One-shot import of a Last.fm scrobble history into Navidrome.',
)]
class ImportLastFmCommand extends Command
{
    public function __construct(
        private readonly LastFmImporter $importer,
        private readonly RunHistoryRecorder $recorder,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'lastfm-user',
                InputArgument::OPTIONAL,
                'Last.fm username whose scrobbles you want to import. '
                . 'Defaults to the LASTFM_USER env var when omitted.',
            )
            ->addOption(
                'api-key',
                null,
                InputOption::VALUE_REQUIRED,
                'Last.fm API key (defaults to LASTFM_API_KEY env var).',
            )
            ->addOption(
                'date-min',
                null,
                InputOption::VALUE_REQUIRED,
                'Only import scrobbles on or after this date (YYYY-MM-DD or ISO).',
            )
            ->addOption(
                'date-max',
                null,
                InputOption::VALUE_REQUIRED,
                'Only import scrobbles before this date (YYYY-MM-DD or ISO).',
            )
            ->addOption(
                'tolerance',
                null,
                InputOption::VALUE_REQUIRED,
                'Dedup tolerance window in seconds (default 60).',
                '60',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Fetch + match + count, but do not write to the Navidrome DB.',
            )
            ->addOption(
                'show-unmatched',
                null,
                InputOption::VALUE_REQUIRED,
                'How many unmatched (artist, title) pairs to print, ordered by scrobble count DESC. 0 to hide. "all" to show everything.',
                '50',
            )
            ->addOption(
                'max-scrobbles',
                null,
                InputOption::VALUE_REQUIRED,
                'Stop after processing N scrobbles (safety cap). Omit for no cap.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $apiKey = (string) ($input->getOption('api-key') ?? $_ENV['LASTFM_API_KEY'] ?? getenv('LASTFM_API_KEY') ?: '');
        if ($apiKey === '') {
            $io->error('Provide an API key with --api-key or LASTFM_API_KEY env var. Get one at https://www.last.fm/api/account/create');

            return Command::FAILURE;
        }

        $user = (string) ($input->getArgument('lastfm-user') ?? $_ENV['LASTFM_USER'] ?? getenv('LASTFM_USER') ?: '');
        if ($user === '') {
            $io->error('Provide a Last.fm username as the first argument or via the LASTFM_USER env var.');

            return Command::FAILURE;
        }
        $dateMin = $this->parseDate($input->getOption('date-min'));
        $dateMax = $this->parseDate($input->getOption('date-max'));
        $tolerance = max(0, (int) $input->getOption('tolerance'));
        $dryRun = (bool) $input->getOption('dry-run');

        $io->section(sprintf(
            'Importing scrobbles for Last.fm user "%s"%s%s%s',
            $user,
            $dateMin ? ' from ' . $dateMin->format('Y-m-d') : '',
            $dateMax ? ' to ' . $dateMax->format('Y-m-d') : '',
            $dryRun ? ' [DRY-RUN]' : '',
        ));

        try {
            $maxScrobbles = $input->getOption('max-scrobbles');
            $maxScrobblesInt = $maxScrobbles !== null ? max(1, (int) $maxScrobbles) : null;
            $em = $this->em;
            $report = $this->recorder->record(
                type: RunHistory::TYPE_LASTFM_IMPORT,
                reference: $user,
                label: 'Last.fm import — ' . $user . ($dryRun ? ' [dry-run]' : ''),
                action: fn (RunHistory $entry) => $this->importer->import(
                    apiKey: $apiKey,
                    lastFmUser: $user,
                    dateMin: $dateMin,
                    dateMax: $dateMax,
                    toleranceSeconds: $tolerance,
                    dryRun: $dryRun,
                    progress: function (int $f, int $i, int $d, int $u) use ($io): void {
                        // Progress signature is fixed (4 ints) — skipped is shown
                        // only in the final summary.
                        $io->writeln(sprintf(
                            '  fetched=%d  inserted=%d  duplicates=%d  unmatched=%d',
                            $f,
                            $i,
                            $d,
                            $u,
                        ));
                    },
                    maxScrobbles: $maxScrobblesInt,
                    onScrobble: function (LastFmScrobble $s, string $status, ?string $mfid) use ($entry, $em): void {
                        $em->persist(new LastFmImportTrack(
                            runHistory: $entry,
                            artist: $s->artist,
                            title: $s->title,
                            album: $s->album,
                            mbid: $s->mbid,
                            playedAt: $s->playedAt,
                            status: $status,
                            matchedMediaFileId: $mfid,
                        ));
                    },
                ),
                extractMetrics: static fn (ImportReport $r) => [
                    'fetched' => $r->fetched,
                    'inserted' => $r->inserted,
                    'duplicates' => $r->duplicates,
                    'unmatched' => $r->unmatched,
                    'skipped' => $r->skipped,
                    'cache_hits_positive' => $r->cacheHitsPositive,
                    'cache_hits_negative' => $r->cacheHitsNegative,
                    'cache_misses' => $r->cacheMisses,
                    'unmatched_artists' => $r->unmatchedArtistsRanking(100),
                    'dry_run' => $dryRun,
                    'date_min' => $dateMin?->format('Y-m-d'),
                    'date_max' => $dateMax?->format('Y-m-d'),
                ],
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->newLine();
        $io->success(sprintf(
            '%s done. fetched=%d inserted=%d duplicates=%d unmatched=%d skipped=%d',
            $dryRun ? 'Dry-run' : 'Import',
            $report->fetched,
            $report->inserted,
            $report->duplicates,
            $report->unmatched,
            $report->skipped,
        ));

        $this->renderUnmatched($io, $report, (string) $input->getOption('show-unmatched'));

        return Command::SUCCESS;
    }

    private function renderUnmatched(SymfonyStyle $io, \App\LastFm\ImportReport $report, string $showOption): void
    {
        if ($report->distinctUnmatched() === 0) {
            return;
        }

        $limit = strtolower($showOption) === 'all' ? null : max(0, (int) $showOption);
        if ($limit === 0) {
            return;
        }

        $rows = $report->unmatchedRanking($limit);
        $io->section(sprintf(
            'Unmatched scrobbles by play count (showing %d of %d distinct tracks, %d total scrobbles)',
            count($rows),
            $report->distinctUnmatched(),
            $report->unmatched,
        ));

        $tableRows = array_map(static fn (array $r) => [
            $r['count'],
            $r['artist'],
            $r['title'],
            $r['album'],
        ], $rows);
        $io->table(['Plays', 'Artist', 'Title', 'Album'], $tableRows);

        $io->note('These tracks were scrobbled on Last.fm but were not found in your Navidrome library (different artist/title or absent). Import will skip them until they are added/renamed.');
    }

    private function parseDate(?string $raw): ?\DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        try {
            return new \DateTimeImmutable($raw);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException(sprintf('Invalid date "%s": %s', $raw, $e->getMessage()));
        }
    }
}
