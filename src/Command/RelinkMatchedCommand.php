<?php

namespace App\Command;

use App\Docker\NavidromeContainerException;
use App\Docker\NavidromeContainerManager;
use App\Entity\RunHistory;
use App\Service\MatchedRelinker;
use App\Service\RunHistoryRecorder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Moves already-written scrobbles to the track the current matching cascade
 * picks (issue #285). Only rows written by the sync (`matched`), minus manual
 * aliases and MBID matches; native Navidrome plays are never touched.
 */
#[AsCommand(
    name: 'app:scrobbles:relink',
    description: 'Re-match already-written Navidrome scrobbles and move those the current cascade sends elsewhere.',
)]
class RelinkMatchedCommand extends Command
{
    public function __construct(
        private readonly MatchedRelinker $relinker,
        private readonly RunHistoryRecorder $recorder,
        private readonly NavidromeContainerManager $container,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('artist', null, InputOption::VALUE_REQUIRED, 'Scrobbled artist (substring, case-insensitive).')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Scrobbled title (substring).')
            ->addOption('album', null, InputOption::VALUE_REQUIRED, 'Scrobbled album (substring).')
            ->addOption('from-album', null, InputOption::VALUE_REQUIRED, 'Album of the CURRENT Navidrome target (substring), e.g. "Instrumentales".')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Max matched scrobbles examined.', (string) MatchedRelinker::MAX_SCROBBLES)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show the planned moves, write nothing.')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip the « Navidrome is running » safety check.')
            ->addOption('auto-stop', null, InputOption::VALUE_NONE, 'Stop Navidrome for the write and restart it afterwards.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $opt = static fn (string $name): ?string => is_string($input->getOption($name)) && trim($input->getOption($name)) !== ''
            ? trim($input->getOption($name))
            : null;
        $artist = $opt('artist');
        $title = $opt('title');
        $album = $opt('album');
        $fromAlbum = $opt('from-album');
        $limit = max(1, (int) $input->getOption('limit'));
        $dryRun = (bool) $input->getOption('dry-run');
        $autoStop = (bool) $input->getOption('auto-stop');

        if (!$dryRun && !$autoStop) {
            try {
                $this->container->assertSafeToWrite((bool) $input->getOption('force'));
            } catch (NavidromeContainerException $e) {
                $io->error($e->getMessage());

                return Command::FAILURE;
            }
        }

        $plan = $this->relinker->plan($artist, $title, $album, $fromAlbum, $limit);
        $io->table(
            ['Écoutes', 'Artiste', 'Titre', 'Album scrobblé', 'Album actuel', '→ Nouvel album', 'Stratégie'],
            array_map(static fn (array $g) => [
                $g['count'], $g['artist'], $g['title'], $g['album'], $g['from_album'], $g['to_album'], $g['strategy'],
            ], $plan['groups']),
        );
        $io->writeln(sprintf(
            'examinées=%d à déplacer=%d deviendraient non matchées (laissées)=%d cible disparue de la bibliothèque (laissées)=%d erreurs Last.fm=%d',
            $plan['considered'],
            count($plan['moves']),
            $plan['would_unmatch'],
            $plan['stale_target'],
            $plan['api_errors'],
        ));
        if ($dryRun || $plan['moves'] === []) {
            $io->success($dryRun ? 'Dry-run : rien n\'est écrit.' : 'Rien à déplacer.');

            return Command::SUCCESS;
        }

        try {
            $written = $this->recorder->record(
                type: RunHistory::TYPE_NAVIDROME_RELINK,
                reference: 'matched',
                label: 'Navidrome relink (CLI)',
                action: fn (RunHistory $entry) => $autoStop
                    ? $this->container->runWithNavidromeStopped(fn () => $this->relinker->apply($plan['moves'], $entry))
                    : $this->relinker->apply($plan['moves'], $entry),
                extractMetrics: static fn (array $r): array => $r + [
                    'planned' => count($plan['moves']),
                    'would_unmatch' => $plan['would_unmatch'],
                    'stale_target' => $plan['stale_target'],
                ],
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Déplacées=%d (dont %d sur une écoute déjà présente → duplicate), ligne Navidrome introuvable (laissées telles quelles)=%d, sauvegarde=%s',
            $written['moved'] + $written['duplicates'],
            $written['duplicates'],
            $written['missing_old'],
            $written['backup'] ?? '-',
        ));

        return Command::SUCCESS;
    }
}
