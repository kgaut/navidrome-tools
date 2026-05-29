<?php

namespace App\Command;

use App\Service\LastFmSessionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-shot interactive setup: exchanges (LASTFM_USER, password) for a
 * never-expiring Last.fm session key (SK) via auth.getMobileSession, and
 * stores it in the Setting table. The SK is then reused by the loves-sync
 * commands without requiring the password — keep that out of env files.
 */
#[AsCommand(
    name: 'app:lastfm:auth',
    description: 'Obtain and store a Last.fm session key for love/unlove writes.',
)]
class LastFmAuthCommand extends Command
{
    public function __construct(
        private readonly LastFmSessionService $sessions,
        private readonly string $defaultApiKey,
        private readonly string $defaultApiSecret,
        private readonly string $defaultUser,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('user', InputArgument::OPTIONAL, 'Last.fm username (defaults to LASTFM_USER).')
            ->addOption('api-key', null, InputOption::VALUE_REQUIRED, 'Last.fm API key (defaults to LASTFM_API_KEY).')
            ->addOption('api-secret', null, InputOption::VALUE_REQUIRED, 'Last.fm API secret (defaults to LASTFM_API_SECRET).')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Last.fm password (prompted if absent — preferred).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $user = (string) ($input->getArgument('user') ?: $this->defaultUser);
        $apiKey = (string) ($input->getOption('api-key') ?: $this->defaultApiKey);
        $apiSecret = (string) ($input->getOption('api-secret') ?: $this->defaultApiSecret);
        $password = (string) ($input->getOption('password') ?? '');

        if ($user === '' || $apiKey === '' || $apiSecret === '') {
            $io->error('LASTFM_USER, LASTFM_API_KEY and LASTFM_API_SECRET must all be set (env or flags).');
            return Command::FAILURE;
        }

        if ($password === '') {
            $question = new Question(sprintf('Mot de passe Last.fm pour %s : ', $user));
            $question->setHidden(true);
            $question->setHiddenFallback(false);
            $password = (string) $io->askQuestion($question);
        }
        if ($password === '') {
            $io->error('Mot de passe requis.');
            return Command::FAILURE;
        }

        try {
            $sk = $this->sessions->obtainAndStore($apiKey, $apiSecret, $user, $password);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf('Session key stockée pour %s (sk %s…).', $user, substr($sk, 0, 8)));
        return Command::SUCCESS;
    }
}
