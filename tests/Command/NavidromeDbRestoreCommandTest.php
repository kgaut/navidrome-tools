<?php

namespace App\Tests\Command;

use App\Command\NavidromeDbRestoreCommand;
use App\Docker\DockerCli;
use App\Docker\NavidromeContainerConfig;
use App\Docker\NavidromeContainerManager;
use App\Navidrome\NavidromeDbBackup;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class NavidromeDbRestoreCommandTest extends TestCase
{
    /** @var list<string> */
    private array $createdPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->createdPaths as $path) {
            foreach (['', '-wal', '-shm'] as $suffix) {
                @unlink($path . $suffix);
            }
            foreach (glob($path . '.backup-*') ?: [] as $backup) {
                @unlink($backup);
                @unlink($backup . '-wal');
                @unlink($backup . '-shm');
            }
        }
        $this->createdPaths = [];
    }

    public function testListShowsAvailableBackups(): void
    {
        $dbPath = $this->makeHealthySqliteDb();
        $backup = new NavidromeDbBackup($dbPath, 5);
        $backup->backup();

        $tester = $this->makeTester($dbPath, $backup);
        $exit = $tester->execute(['--list' => true]);

        $this->assertSame(0, $exit);
        $output = $tester->getDisplay();
        $this->assertStringContainsString('Timestamp', $output);
        $this->assertMatchesRegularExpression('/\d{14}/', $output);
        $this->assertStringContainsString('1 backup(s) disponible(s)', $output);
    }

    public function testListShowsEmptyWhenNoBackups(): void
    {
        $dbPath = $this->makeHealthySqliteDb();
        $backup = new NavidromeDbBackup($dbPath, 5);

        $tester = $this->makeTester($dbPath, $backup);
        $exit = $tester->execute(['--list' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Aucun backup disponible', $tester->getDisplay());
    }

    public function testRestoreFailsWhenNoBackupExists(): void
    {
        $dbPath = $this->makeHealthySqliteDb();
        $backup = new NavidromeDbBackup($dbPath, 5);

        $tester = $this->makeTester($dbPath, $backup);
        $exit = $tester->execute([], ['interactive' => false]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('Aucun backup disponible', $tester->getDisplay());
    }

    public function testRestoreLatestWhenNoTimestampGiven(): void
    {
        $dbPath = $this->makeHealthySqliteDb();
        $backup = new NavidromeDbBackup($dbPath, 5);
        $backup->backup();

        $originalContent = (string) file_get_contents($dbPath);

        // Mutate the live DB — restore must put it back to the snapshot.
        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->exec("INSERT INTO t (v) VALUES ('mutated')");
        unset($pdo);
        $this->assertNotSame($originalContent, (string) file_get_contents($dbPath));

        $tester = $this->makeTester($dbPath, $backup);
        $exit = $tester->execute([], ['interactive' => false]);

        $this->assertSame(0, $exit, $tester->getDisplay());
        $this->assertSame($originalContent, file_get_contents($dbPath));
        $this->assertStringContainsString('restaurée', $tester->getDisplay());
    }

    public function testRestoreSpecificTimestamp(): void
    {
        $dbPath = $this->makeHealthySqliteDb();
        $backup = new NavidromeDbBackup($dbPath, 5);

        // Make a deterministic backup at a known timestamp.
        $stamp = '20200101000001';
        copy($dbPath, $dbPath . '.backup-' . $stamp);

        // Mutate the live DB.
        $pdo = new \PDO('sqlite:' . $dbPath);
        $pdo->exec("INSERT INTO t (v) VALUES ('mutated')");
        unset($pdo);

        $tester = $this->makeTester($dbPath, $backup);
        $exit = $tester->execute(['--timestamp' => $stamp], ['interactive' => false]);

        $this->assertSame(0, $exit, $tester->getDisplay());
        $this->assertStringContainsString($stamp, $tester->getDisplay());
    }

    public function testRestoreFailsOnUnknownTimestamp(): void
    {
        $dbPath = $this->makeHealthySqliteDb();
        $backup = new NavidromeDbBackup($dbPath, 5);
        $backup->backup();

        $tester = $this->makeTester($dbPath, $backup);
        $exit = $tester->execute(['--timestamp' => '99999999999999'], ['interactive' => false]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('Aucun backup trouvé', $tester->getDisplay());
    }

    private function makeHealthySqliteDb(): string
    {
        $path = sys_get_temp_dir() . '/nv-cmd-restore-' . bin2hex(random_bytes(4)) . '.db';
        $pdo = new \PDO('sqlite:' . $path);
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
        $pdo->exec("INSERT INTO t (v) VALUES ('one')");
        unset($pdo);
        $this->createdPaths[] = $path;

        return $path;
    }

    private function makeTester(string $dbPath, NavidromeDbBackup $backup): CommandTester
    {
        // Empty container name → NavidromeContainerManager.runWithNavidromeStopped()
        // skips all docker orchestration and just calls the closure. Lets the
        // command exercise its full code path without a mocked CLI.
        $manager = new NavidromeContainerManager(
            new DockerCli(),
            new NavidromeContainerConfig(''),
            $backup,
        );

        $command = new NavidromeDbRestoreCommand($backup, $manager);
        $app = new Application();
        $app->add($command);

        return new CommandTester($app->find('app:navidrome:db:restore'));
    }
}
