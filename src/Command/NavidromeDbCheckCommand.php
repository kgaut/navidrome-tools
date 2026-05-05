<?php

namespace App\Command;

use App\Navidrome\NavidromeDbBackup;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Run a structural sanity check on the Navidrome SQLite file. By default
 * `PRAGMA quick_check` (fast — same check we run automatically before /
 * after every `app:lastfm:process` action). With `--integrity` runs the
 * fuller `PRAGMA integrity_check`, which also verifies row/index
 * consistency — slower (1-2 min on a 100k-track library) and only worth
 * it for an explicit diagnostic.
 *
 * Read-only — does not require Navidrome to be stopped.
 */
#[AsCommand(
    name: 'app:navidrome:db:check',
    description: 'Vérifier l\'intégrité du fichier SQLite Navidrome (quick_check par défaut, --integrity pour le check complet).',
)]
class NavidromeDbCheckCommand extends Command
{
    public function __construct(
        private readonly NavidromeDbBackup $backup,
        private readonly string $dbPath,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'integrity',
            null,
            InputOption::VALUE_NONE,
            'Lancer PRAGMA integrity_check (lent — vérifie la cohérence row/index en plus de la structure).',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!is_file($this->dbPath)) {
            $io->error(sprintf('DB Navidrome introuvable : %s', $this->dbPath));
            return Command::FAILURE;
        }

        if ((bool) $input->getOption('integrity')) {
            return $this->runIntegrityCheck($io);
        }

        try {
            $this->backup->quickCheck();
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $io->success('PRAGMA quick_check : ok');
        return Command::SUCCESS;
    }

    private function runIntegrityCheck(SymfonyStyle $io): int
    {
        try {
            $pdo = new \PDO('sqlite:file:' . $this->dbPath . '?mode=ro', null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
            $stmt = $pdo->query('PRAGMA integrity_check');
            /** @var list<string> $rows */
            $rows = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_COLUMN) : [];
        } catch (\PDOException $e) {
            $io->error(sprintf('Ouverture/lecture impossible : %s', $e->getMessage()));
            return Command::FAILURE;
        }

        if ($rows === ['ok']) {
            $io->success('PRAGMA integrity_check : ok');
            return Command::SUCCESS;
        }

        $io->error('PRAGMA integrity_check a remonté des erreurs :');
        foreach ($rows as $line) {
            $io->writeln('  • ' . $line);
        }
        return Command::FAILURE;
    }
}
