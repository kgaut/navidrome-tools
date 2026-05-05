<?php

namespace App\Tests\Command;

use App\Command\NavidromeDbCheckCommand;
use App\Navidrome\NavidromeDbBackup;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class NavidromeDbCheckCommandTest extends TestCase
{
    /** @var list<string> */
    private array $createdPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->createdPaths as $path) {
            foreach (['', '-wal', '-shm'] as $suffix) {
                @unlink($path . $suffix);
            }
        }
        $this->createdPaths = [];
    }

    public function testQuickCheckExitsZeroOnHealthyDb(): void
    {
        $dbPath = $this->makeHealthySqliteDb();
        $tester = $this->makeTester($dbPath);

        $exit = $tester->execute([]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('quick_check', $tester->getDisplay());
        $this->assertStringContainsString('ok', strtolower($tester->getDisplay()));
    }

    public function testQuickCheckExitsNonZeroOnCorruptDb(): void
    {
        $dbPath = sys_get_temp_dir() . '/nv-cmd-corrupt-' . bin2hex(random_bytes(4)) . '.db';
        // Truncated SQLite header — quick_check refuses to open.
        file_put_contents($dbPath, str_repeat("\x00", 50));
        $this->createdPaths[] = $dbPath;

        $tester = $this->makeTester($dbPath);
        $exit = $tester->execute([]);

        $this->assertNotSame(0, $exit);
    }

    public function testIntegrityCheckExitsZeroOnHealthyDb(): void
    {
        $dbPath = $this->makeHealthySqliteDb();
        $tester = $this->makeTester($dbPath);

        $exit = $tester->execute(['--integrity' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('integrity_check', $tester->getDisplay());
    }

    public function testIntegrityCheckOnMissingFileFails(): void
    {
        $dbPath = '/tmp/no-such-' . bin2hex(random_bytes(4)) . '.db';
        $tester = $this->makeTester($dbPath);

        $exit = $tester->execute([]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('introuvable', $tester->getDisplay());
    }

    private function makeHealthySqliteDb(): string
    {
        $path = sys_get_temp_dir() . '/nv-cmd-check-' . bin2hex(random_bytes(4)) . '.db';
        $pdo = new \PDO('sqlite:' . $path);
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
        $pdo->exec("INSERT INTO t (v) VALUES ('one')");
        unset($pdo);
        $this->createdPaths[] = $path;

        return $path;
    }

    private function makeTester(string $dbPath): CommandTester
    {
        $command = new NavidromeDbCheckCommand(
            new NavidromeDbBackup($dbPath, 3),
            $dbPath,
        );
        $app = new Application();
        $app->add($command);

        return new CommandTester($app->find('app:navidrome:db:check'));
    }
}
