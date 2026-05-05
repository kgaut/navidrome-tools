<?php

namespace App\Tests\Docker;

use App\Docker\ContainerStatus;
use App\Docker\DockerCli;
use App\Docker\NavidromeContainerConfig;
use App\Docker\NavidromeContainerException;
use App\Docker\NavidromeContainerManager;
use App\Navidrome\NavidromeDbBackup;
use PHPUnit\Framework\TestCase;

class NavidromeContainerManagerTest extends TestCase
{
    /** @var list<string> Files (and their wal/shm siblings) to clean up after each test */
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

    public function testStatusDisabledWhenContainerNameEmpty(): void
    {
        $manager = new NavidromeContainerManager(
            new DockerCli(),
            new NavidromeContainerConfig(''),
            $this->makeNoopBackup(),
        );
        $this->assertSame(ContainerStatus::Disabled, $manager->getStatus());
    }

    public function testStatusRunning(): void
    {
        $manager = $this->makeManager(state: ['Running' => true, 'Status' => 'running']);
        $this->assertSame(ContainerStatus::Running, $manager->getStatus());
    }

    public function testStatusStopped(): void
    {
        $manager = $this->makeManager(state: ['Running' => false, 'Status' => 'exited']);
        $this->assertSame(ContainerStatus::Stopped, $manager->getStatus());
    }

    public function testStatusNotFound(): void
    {
        $manager = $this->makeManager(state: null);
        $this->assertSame(ContainerStatus::NotFound, $manager->getStatus());
    }

    public function testStatusUnknownWhenInspectFails(): void
    {
        $manager = $this->makeManager(throwOnInspect: 'cannot connect to the docker daemon');
        $this->assertSame(ContainerStatus::Unknown, $manager->getStatus());
    }

    public function testAssertSafeToWritePassesWhenStopped(): void
    {
        $manager = $this->makeManager(state: ['Running' => false]);
        $manager->assertSafeToWrite();
        $this->addToAssertionCount(1);
    }

    public function testAssertSafeToWritePassesWhenDisabled(): void
    {
        $manager = new NavidromeContainerManager(
            new DockerCli(),
            new NavidromeContainerConfig(''),
            $this->makeNoopBackup(),
        );
        $manager->assertSafeToWrite();
        $this->addToAssertionCount(1);
    }

    public function testAssertSafeToWriteThrowsWhenRunning(): void
    {
        $manager = $this->makeManager(state: ['Running' => true]);
        $this->expectException(NavidromeContainerException::class);
        $this->expectExceptionMessageMatches('/tourne actuellement/');
        $manager->assertSafeToWrite();
    }

    public function testAssertSafeToWriteThrowsWhenUnknown(): void
    {
        $manager = $this->makeManager(throwOnInspect: 'cannot connect');
        $this->expectException(NavidromeContainerException::class);
        $this->expectExceptionMessageMatches('/Impossible de v[ée]rifier/u');
        $manager->assertSafeToWrite();
    }

    public function testAssertSafeToWriteForceBypassesRunningCheck(): void
    {
        $manager = $this->makeManager(state: ['Running' => true]);
        $manager->assertSafeToWrite(force: true);
        $this->addToAssertionCount(1);
    }

    public function testStartCallsCliWithContainerName(): void
    {
        $cli = new FakeDockerCli(state: ['Running' => false]);
        $manager = new NavidromeContainerManager($cli, new NavidromeContainerConfig('navidrome'), $this->makeNoopBackup());
        $manager->start();
        $this->assertSame(['start', 'navidrome'], $cli->lastAction);
    }

    public function testStopCallsCliWithContainerName(): void
    {
        $cli = new FakeDockerCli(state: ['Running' => true]);
        $manager = new NavidromeContainerManager($cli, new NavidromeContainerConfig('navidrome'), $this->makeNoopBackup());
        $manager->stop();
        $this->assertSame(['stop', 'navidrome'], $cli->lastAction);
    }

    public function testStopForwardsConfiguredTimeoutToDockerCli(): void
    {
        $cli = new FakeDockerCli(state: ['Running' => true]);
        $manager = new NavidromeContainerManager(
            $cli,
            new NavidromeContainerConfig('navidrome', stopTimeoutSeconds: 75),
            $this->makeNoopBackup(),
        );
        $manager->stop();
        $this->assertSame(75, $cli->lastStopTimeout);
    }

    public function testStartThrowsWhenNotConfigured(): void
    {
        $manager = new NavidromeContainerManager(
            new DockerCli(),
            new NavidromeContainerConfig(''),
            $this->makeNoopBackup(),
        );
        $this->expectException(NavidromeContainerException::class);
        $manager->start();
    }

    public function testRunWithStoppedSkipsOrchestrationWhenDisabled(): void
    {
        $cli = new FakeDockerCli();
        $manager = new NavidromeContainerManager($cli, new NavidromeContainerConfig(''), $this->makeNoopBackup());
        $result = $manager->runWithNavidromeStopped(fn () => 42);
        $this->assertSame(42, $result);
        $this->assertSame([], $cli->actions);
    }

    public function testRunWithStoppedSkipsOrchestrationWhenAlreadyStopped(): void
    {
        $cli = new FakeDockerCli(state: ['Running' => false]);
        $manager = new NavidromeContainerManager($cli, new NavidromeContainerConfig('navidrome'), $this->makeNoopBackup());
        $result = $manager->runWithNavidromeStopped(fn () => 'ok');
        $this->assertSame('ok', $result);
        $this->assertSame([], $cli->actions);
    }

    public function testRunWithStoppedSkipsOrchestrationWhenNotFound(): void
    {
        $cli = new FakeDockerCli(state: null);
        $manager = new NavidromeContainerManager($cli, new NavidromeContainerConfig('navidrome'), $this->makeNoopBackup());
        $manager->runWithNavidromeStopped(fn () => null);
        $this->assertSame([], $cli->actions);
    }

    public function testRunWithStoppedThrowsWhenStatusUnknown(): void
    {
        $cli = new FakeDockerCli(throwOnInspect: 'cannot connect');
        $manager = new NavidromeContainerManager($cli, new NavidromeContainerConfig('navidrome'), $this->makeNoopBackup());
        $this->expectException(NavidromeContainerException::class);
        $this->expectExceptionMessageMatches('/ind[ée]termin[ée]/u');
        $manager->runWithNavidromeStopped(fn () => 'never');
    }

    public function testRunWithStoppedStopsRunsAndRestartsWhenRunning(): void
    {
        $cli = new FakeDockerCli(state: ['Running' => true]);
        $manager = new NavidromeContainerManager($cli, new NavidromeContainerConfig('navidrome'), $this->makeNoopBackup());
        $calls = [];
        $result = $manager->runWithNavidromeStopped(function () use (&$calls): string {
            $calls[] = 'action';

            return 'imported';
        });
        $this->assertSame('imported', $result);
        $this->assertSame(
            [['stop', 'navidrome'], ['start', 'navidrome']],
            $cli->actions,
        );
        $this->assertSame(['action'], $calls);
    }

    public function testRunWithStoppedRestartsEvenWhenActionThrows(): void
    {
        $cli = new FakeDockerCli(state: ['Running' => true]);
        $manager = new NavidromeContainerManager($cli, new NavidromeContainerConfig('navidrome'), $this->makeNoopBackup());

        $thrown = null;
        try {
            $manager->runWithNavidromeStopped(function (): void {
                throw new \RuntimeException('import boom');
            });
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(\RuntimeException::class, $thrown);
        $this->assertSame('import boom', $thrown->getMessage());
        $this->assertSame(
            [['stop', 'navidrome'], ['start', 'navidrome']],
            $cli->actions,
        );
    }

    public function testRunWithStoppedReportsCompoundFailureWhenStartAlsoFails(): void
    {
        $cli = new FakeDockerCli(state: ['Running' => true]);
        $cli->startShouldFail = true;
        $manager = new NavidromeContainerManager($cli, new NavidromeContainerConfig('navidrome'), $this->makeNoopBackup());

        $thrown = null;
        try {
            $manager->runWithNavidromeStopped(function (): void {
                throw new \RuntimeException('import boom');
            });
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(NavidromeContainerException::class, $thrown);
        $this->assertStringContainsString('import boom', $thrown->getMessage());
        $this->assertStringContainsString('redémarrage', $thrown->getMessage());
        $this->assertInstanceOf(\RuntimeException::class, $thrown->getPrevious());
    }

    public function testRunWithStoppedPropagatesStartFailureWhenActionSucceeded(): void
    {
        $cli = new FakeDockerCli(state: ['Running' => true]);
        $cli->startShouldFail = true;
        $manager = new NavidromeContainerManager($cli, new NavidromeContainerConfig('navidrome'), $this->makeNoopBackup());

        $this->expectException(NavidromeContainerException::class);
        $this->expectExceptionMessageMatches('/docker start/');
        $manager->runWithNavidromeStopped(fn () => 'ok');
    }

    public function testRunWithStoppedPollsBeforeRunningAction(): void
    {
        // Simulate a Navidrome that takes 2 inspect cycles to actually
        // shutdown after `docker stop` returns. The action MUST NOT fire
        // until inspect reports Running:false.
        $cli = new FakeDockerCli(state: ['Running' => true]);
        $cli->simulateSlowShutdown(2);
        $manager = new NavidromeContainerManager(
            $cli,
            new NavidromeContainerConfig('navidrome', stopWaitCeilingSeconds: 5),
            $this->makeNoopBackup(),
        );

        $calls = [];
        $result = $manager->runWithNavidromeStopped(function () use ($cli, &$calls) {
            $calls[] = 'action';
            // By the time the action runs, inspect must already see stopped.
            $state = $cli->inspectState('navidrome');
            $this->assertNotNull($state);
            $this->assertFalse($state['Running']);

            return 'ok';
        });
        $this->assertSame('ok', $result);
        $this->assertSame(['action'], $calls);
    }

    public function testRunWithStoppedAbortsWhenContainerStillRunningAfterCeiling(): void
    {
        // Ceiling of 1s; simulate a container that needs 100 inspect cycles
        // to flip — way more than the polling will do in 1 second. Action
        // must NEVER run, no start(), exception explains the reason.
        $cli = new FakeDockerCli(state: ['Running' => true]);
        $cli->simulateSlowShutdown(100);
        $manager = new NavidromeContainerManager(
            $cli,
            new NavidromeContainerConfig('navidrome', stopWaitCeilingSeconds: 1),
            $this->makeNoopBackup(),
        );

        $actionRan = false;
        $thrown = null;
        try {
            $manager->runWithNavidromeStopped(function () use (&$actionRan): void {
                $actionRan = true;
            });
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertFalse($actionRan, 'Action must NOT run while Navidrome is still alive.');
        $this->assertInstanceOf(NavidromeContainerException::class, $thrown);
        $this->assertMatchesRegularExpression('/toujours en cours/', $thrown->getMessage());
        // No start: we never confirmed stop, leaving Navidrome running is
        // safer than re-issuing start to a still-running container.
        $this->assertSame([['stop', 'navidrome']], $cli->actions);
    }

    public function testRunWithStoppedTakesBackupBeforeAction(): void
    {
        $dbPath = $this->makeRealSqliteDb();
        $cli = new FakeDockerCli(state: ['Running' => true]);
        $manager = new NavidromeContainerManager(
            $cli,
            new NavidromeContainerConfig('navidrome'),
            new NavidromeDbBackup($dbPath, 3),
        );

        $backupExistedDuringAction = false;
        $manager->runWithNavidromeStopped(function () use ($dbPath, &$backupExistedDuringAction): void {
            $backupExistedDuringAction = (glob($dbPath . '.backup-*') ?: []) !== [];
        });

        $this->assertTrue($backupExistedDuringAction, 'Backup must exist before action runs.');
    }

    public function testRunWithStoppedAbortsActionWhenIntegrityCheckFails(): void
    {
        // Tampered file: SQLite header replaced with garbage. quickCheck
        // must throw, action must NOT run, but Navidrome must be restarted
        // so the user gets back to a working dashboard.
        $dbPath = sys_get_temp_dir() . '/nv-corrupt-' . bin2hex(random_bytes(4)) . '.db';
        file_put_contents($dbPath, str_repeat("\x00", 4096));
        $this->createdPaths[] = $dbPath;

        $cli = new FakeDockerCli(state: ['Running' => true]);
        $manager = new NavidromeContainerManager(
            $cli,
            new NavidromeContainerConfig('navidrome'),
            new NavidromeDbBackup($dbPath, 3),
        );

        $actionRan = false;
        $thrown = null;
        try {
            $manager->runWithNavidromeStopped(function () use (&$actionRan): void {
                $actionRan = true;
            });
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertFalse($actionRan);
        $this->assertInstanceOf(\RuntimeException::class, $thrown);
        // start() was still called to bring Navidrome back up.
        $this->assertContains(['start', 'navidrome'], $cli->actions);
    }

    public function testRunWithStoppedSkipsPreCheckWhenRequested(): void
    {
        // DB in poor shape (header garbage) — pre-check would normally throw
        // and abort the action. With $skipPreCheck=true the action runs
        // anyway. Then post-check still runs and the corruption is caught
        // before Navidrome restarts.
        $dbPath = sys_get_temp_dir() . '/nv-skip-' . bin2hex(random_bytes(4)) . '.db';
        file_put_contents($dbPath, str_repeat("\x00", 4096));
        $this->createdPaths[] = $dbPath;

        $cli = new FakeDockerCli(state: ['Running' => true]);
        $manager = new NavidromeContainerManager(
            $cli,
            new NavidromeContainerConfig('navidrome'),
            new NavidromeDbBackup($dbPath, 3),
        );

        $actionRan = false;
        try {
            $manager->runWithNavidromeStopped(
                function () use (&$actionRan, $dbPath): void {
                    $actionRan = true;
                    // Heal the DB so post-check passes (mimics what the future
                    // db:restore command does — copies a backup over).
                    $this->seedHealthySqliteAt($dbPath);
                },
                skipPreCheck: true,
            );
        } catch (\Throwable) {
            // We don't care about the exception here — the assertion is on
            // whether the action ran despite the broken pre-state.
        }

        $this->assertTrue($actionRan, 'skipPreCheck must let the action run despite a broken DB');
    }

    public function testRunWithStoppedRestoresWhenPostActionQuickCheckFails(): void
    {
        // Healthy DB → backup taken → action corrupts the DB → post-check
        // fails → restore() puts the snapshot back → Navidrome restarts on
        // a clean DB → an explicit corruption exception surfaces.
        $dbPath = $this->makeRealSqliteDb();
        $originalContent = (string) file_get_contents($dbPath);
        $cli = new FakeDockerCli(state: ['Running' => true]);
        $manager = new NavidromeContainerManager(
            $cli,
            new NavidromeContainerConfig('navidrome'),
            new NavidromeDbBackup($dbPath, 3),
        );

        $thrown = null;
        try {
            $manager->runWithNavidromeStopped(function () use ($dbPath): void {
                // Simulate the action partially writing then crashing the file
                // by overwriting with garbage. Mimics a torn write / kill -9
                // mid-INSERT scenario.
                file_put_contents($dbPath, str_repeat("\xFF", 4096));
            });
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(NavidromeContainerException::class, $thrown);
        $this->assertMatchesRegularExpression('/restaur[ée]e automatiquement/u', $thrown->getMessage());
        // The DB on disk must equal the snapshot, not the corrupt payload.
        $this->assertSame($originalContent, file_get_contents($dbPath), 'restore must put the snapshot back');
        // Navidrome restarted on the clean DB.
        $this->assertContains(['start', 'navidrome'], $cli->actions);
    }

    public function testRunWithStoppedSkipsRestartWhenRestoreFails(): void
    {
        // Backup has been taken, action corrupts. We then sabotage the
        // backup file too — restore can't succeed → manager must NOT
        // restart Navidrome (DB in unknown state) and surface a compound
        // exception telling the user to recover manually.
        $dbPath = $this->makeRealSqliteDb();
        $cli = new FakeDockerCli(state: ['Running' => true]);
        $manager = new NavidromeContainerManager(
            $cli,
            new NavidromeContainerConfig('navidrome'),
            new NavidromeDbBackup($dbPath, 3),
        );

        $thrown = null;
        try {
            $manager->runWithNavidromeStopped(function () use ($dbPath): void {
                // Corrupt the live DB.
                file_put_contents($dbPath, str_repeat("\xFF", 4096));
                // Also corrupt the backup that was just taken — restore
                // will refuse to use it.
                $backups = glob($dbPath . '.backup-*') ?: [];
                $this->assertNotEmpty($backups);
                file_put_contents((string) end($backups), str_repeat("\x00", 4096));
            });
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(NavidromeContainerException::class, $thrown);
        $this->assertMatchesRegularExpression('/restore automatique a [ée]chou[ée]/u', $thrown->getMessage());
        $this->assertNotContains(
            ['start', 'navidrome'],
            $cli->actions,
            'Navidrome must NOT be restarted when the DB is in an unknown state',
        );
    }

    public function testRunWithStoppedChainsActionExceptionAfterCorruption(): void
    {
        // Action throws AND corrupts in the same shot. Post-check catches
        // the corruption ; the surfaced exception must keep the original
        // action error chained as `previous` so the user can see what
        // originally went wrong.
        $dbPath = $this->makeRealSqliteDb();
        $cli = new FakeDockerCli(state: ['Running' => true]);
        $manager = new NavidromeContainerManager(
            $cli,
            new NavidromeContainerConfig('navidrome'),
            new NavidromeDbBackup($dbPath, 3),
        );

        $thrown = null;
        try {
            $manager->runWithNavidromeStopped(function () use ($dbPath): void {
                file_put_contents($dbPath, str_repeat("\xFF", 4096));
                throw new \RuntimeException('action crashed mid-write');
            });
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(NavidromeContainerException::class, $thrown);
        $previous = $thrown->getPrevious();
        $this->assertInstanceOf(\RuntimeException::class, $previous);
        $this->assertSame('action crashed mid-write', $previous->getMessage());
    }

    public function testRunWithStoppedDoesNotRunPostCheckWhenPreCheckFailed(): void
    {
        // Pre-check fails (corrupt DB, no skip). Action does NOT run, so
        // post-check is also skipped — we don't double-process the same
        // corruption.
        $dbPath = sys_get_temp_dir() . '/nv-precheck-' . bin2hex(random_bytes(4)) . '.db';
        file_put_contents($dbPath, str_repeat("\x00", 4096));
        $this->createdPaths[] = $dbPath;

        $cli = new FakeDockerCli(state: ['Running' => true]);
        $manager = new NavidromeContainerManager(
            $cli,
            new NavidromeContainerConfig('navidrome'),
            new NavidromeDbBackup($dbPath, 3),
        );

        $actionRan = false;
        try {
            $manager->runWithNavidromeStopped(function () use (&$actionRan): void {
                $actionRan = true;
            });
        } catch (\Throwable) {
            // expected
        }

        $this->assertFalse($actionRan);
        // start() was still issued (existing behaviour) — pre-check failure
        // means « don't write », not « never bring Navidrome back up ».
        $this->assertContains(['start', 'navidrome'], $cli->actions);
    }

    /**
     * @param array<string, mixed>|null $state
     */
    private function makeManager(?array $state = null, ?string $throwOnInspect = null): NavidromeContainerManager
    {
        return new NavidromeContainerManager(
            new FakeDockerCli($state, $throwOnInspect),
            new NavidromeContainerConfig('navidrome'),
            $this->makeNoopBackup(),
        );
    }

    private function makeNoopBackup(): NavidromeDbBackup
    {
        // Pointing at a path that does not exist makes both backup() and
        // quickCheck() into no-ops — perfect for tests that only care
        // about start/stop orchestration.
        return new NavidromeDbBackup('/tmp/does-not-exist-' . bin2hex(random_bytes(4)) . '.db');
    }

    private function makeRealSqliteDb(): string
    {
        $path = sys_get_temp_dir() . '/nv-mgr-test-' . bin2hex(random_bytes(4)) . '.db';
        $this->seedHealthySqliteAt($path);
        $this->createdPaths[] = $path;

        return $path;
    }

    private function seedHealthySqliteAt(string $path): void
    {
        if (file_exists($path)) {
            @unlink($path);
        }
        $pdo = new \PDO('sqlite:' . $path);
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');
        $pdo->exec("INSERT INTO t (v) VALUES ('one'), ('two')");
        unset($pdo);
    }
}
