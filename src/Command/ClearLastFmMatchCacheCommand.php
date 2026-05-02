<?php

namespace App\Command;

use App\Repository\LastFmMatchCacheRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:lastfm:cache:clear',
    description: 'Wipe the Last.fm → media_file resolution cache (lastfm_match_cache).',
)]
class ClearLastFmMatchCacheCommand extends Command
{
    public function __construct(
        private readonly LastFmMatchCacheRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'negative-only',
            null,
            InputOption::VALUE_NONE,
            'Drop only negative cache entries (target_media_file_id IS NULL). Useful after adding tracks to the lib — keeps known-good positive resolutions and forces a re-cascade for the unmatched ones.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $negativeOnly = (bool) $input->getOption('negative-only');

        $deleted = $this->repository->purgeAll($negativeOnly);
        $this->em->flush();

        $io->success(sprintf(
            'Cleared %d %s cache row%s from lastfm_match_cache.',
            $deleted,
            $negativeOnly ? 'negative' : 'total',
            $deleted === 1 ? '' : 's',
        ));

        return Command::SUCCESS;
    }
}
