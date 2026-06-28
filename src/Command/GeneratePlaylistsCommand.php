<?php

namespace App\Command;

use App\Entity\RunHistory;
use App\Navidrome\NavidromeRepository;
use App\Playlist\PlaylistGenerator;
use App\Playlist\PlaylistRunResult;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Generate (or refresh) the algorithmic playlists in Navidrome.
 *
 *   app:playlists:generate --list                 # show available definitions
 *   app:playlists:generate --slug=retour-en-arriere [--dry-run]
 *   app:playlists:generate --all [--dry-run]
 *
 * `--slug` generates exactly one definition; `--all` generates every
 * defined playlist. `--dry-run` resolves and prints the track list without
 * writing anything to Navidrome.
 */
#[AsCommand(
    name: 'app:playlists:generate',
    description: 'Generate algorithmic playlists in Navidrome (one via --slug, or all via --all).',
)]
class GeneratePlaylistsCommand extends Command
{
    public function __construct(
        private readonly PlaylistGenerator $generator,
        private readonly NavidromeRepository $navidrome,
        private readonly RunHistoryRecorder $recorder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('slug', null, InputOption::VALUE_REQUIRED, 'Generate only this playlist definition.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Generate every defined playlist.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Resolve and print tracks without writing to Navidrome.')
            ->addOption('list', null, InputOption::VALUE_NONE, 'List available playlist definitions and exit.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ((bool) $input->getOption('list')) {
            return $this->printList($io);
        }

        $slug = $input->getOption('slug');
        $slug = is_string($slug) && $slug !== '' ? $slug : null;
        $all = (bool) $input->getOption('all');
        $dryRun = (bool) $input->getOption('dry-run');

        if ($slug === null && !$all) {
            $io->error('Précisez --slug=<slug> pour une playlist, ou --all pour toutes. (--list pour la liste.)');
            return Command::INVALID;
        }
        if ($slug !== null && $all) {
            $io->error('Options --slug et --all mutuellement exclusives.');
            return Command::INVALID;
        }

        $io->title($dryRun ? 'Génération playlists [dry-run]' : 'Génération playlists');

        try {
            /** @var list<PlaylistRunResult> $results */
            $results = $this->recorder->record(
                type: RunHistory::TYPE_PLAYLIST_GENERATE,
                reference: $slug ?? 'all',
                label: sprintf('Génération playlists [%s%s]', $slug ?? 'toutes', $dryRun ? ' dry-run' : ''),
                action: fn (): array => $this->generator->generate($slug, $dryRun),
                extractMetrics: static fn (array $r): array => ['playlists' => count($r)],
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $this->printResults($io, $results, $dryRun);

        return Command::SUCCESS;
    }

    private function printList(SymfonyStyle $io): int
    {
        $rows = [];
        foreach ($this->generator->listDefinitions() as $d) {
            $rows[] = [$d['slug'], $d['name'], $d['description']];
        }
        $io->table(['slug', 'nom', 'description'], $rows);

        return Command::SUCCESS;
    }

    /**
     * @param list<PlaylistRunResult> $results
     */
    private function printResults(SymfonyStyle $io, array $results, bool $dryRun): void
    {
        if ($results === []) {
            $io->warning('Aucune playlist définie.');
            return;
        }

        if ($dryRun) {
            foreach ($results as $r) {
                $io->section(sprintf('%s — %d morceaux', $r->name, $r->trackCount()));
                if ($r->action === PlaylistRunResult::ACTION_ERROR) {
                    $io->error($r->error ?? 'erreur inconnue');
                    continue;
                }
                $lines = [];
                foreach ($this->navidrome->summarize($r->trackIds) as $t) {
                    $lines[] = sprintf('%s — %s', $t->artist, $t->title);
                }
                $io->listing($lines !== [] ? $lines : ['(vide)']);
            }
        }

        $rows = [];
        foreach ($results as $r) {
            $rows[] = [$r->slug, $r->action, (string) $r->trackCount(), $r->error ?? ''];
        }
        $io->table(['slug', 'action', 'morceaux', 'erreur'], $rows);
        $io->success(sprintf('%d playlist(s) traitée(s).', count($results)));
    }
}
