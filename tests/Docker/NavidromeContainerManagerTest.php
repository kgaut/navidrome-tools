<?php

namespace App\Tests\Docker;

use App\Docker\ContainerStatus;
use App\Docker\DockerCli;
use App\Docker\NavidromeContainerConfig;
use App\Docker\NavidromeContainerException;
use App\Docker\NavidromeContainerManager;
use PHPUnit\Framework\TestCase;

class NavidromeContainerManagerTest extends TestCase
{
    public function testStatusDisabledWhenContainerNameEmpty(): void
    {
        $manager = new NavidromeContainerManager(new DockerCli(), new NavidromeContainerConfig(''));
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
        $manager = new NavidromeContainerManager(new DockerCli(), new NavidromeContainerConfig(''));
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
        $manager = new NavidromeContainerManager($cli, new NavidromeContainerConfig('navidrome'));
        $manager->start();
        $this->assertSame(['start', 'navidrome'], $cli->lastAction);
    }

    public function testStopCallsCliWithContainerName(): void
    {
        $cli = new FakeDockerCli(state: ['Running' => true]);
        $manager = new NavidromeContainerManager($cli, new NavidromeContainerConfig('navidrome'));
        $manager->stop();
        $this->assertSame(['stop', 'navidrome'], $cli->lastAction);
    }

    public function testStartThrowsWhenNotConfigured(): void
    {
        $manager = new NavidromeContainerManager(new DockerCli(), new NavidromeContainerConfig(''));
        $this->expectException(NavidromeContainerException::class);
        $manager->start();
    }

    /**
     * @param array<string, mixed>|null $state
     */
    private function makeManager(?array $state = null, ?string $throwOnInspect = null): NavidromeContainerManager
    {
        return new NavidromeContainerManager(
            new FakeDockerCli($state, $throwOnInspect),
            new NavidromeContainerConfig('navidrome'),
        );
    }
}
