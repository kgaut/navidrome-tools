<?php

namespace App\Tests\Docker;

use App\Docker\DockerCli;
use App\Docker\NavidromeContainerException;

/**
 * Test double for DockerCli — never spawns a process. Records every
 * start/stop call so tests can assert sequences, and lets each test
 * pre-program the inspect outcome (a state array, null = "not found",
 * or a thrown exception simulating a Docker daemon failure). Calling
 * start/stop also mutates the inspected state so subsequent
 * `inspectState` calls reflect the new running state.
 */
final class FakeDockerCli extends DockerCli
{
    /** @var array{0: string, 1: string}|null */
    public ?array $lastAction = null;

    /** @var list<array{0: string, 1: string}> */
    public array $actions = [];

    public bool $startShouldFail = false;
    public bool $stopShouldFail = false;

    /**
     * Last `timeoutSeconds` value passed to {@see stop()} — lets tests
     * assert that the container manager is forwarding the configured
     * graceful-shutdown window to `docker stop -t`.
     */
    public ?int $lastStopTimeout = null;

    /**
     * When non-null, simulates a slow-shutdown container : `stop()` does
     * not flip `Running` to false immediately. Instead, the next N calls
     * to `inspectState()` keep returning Running=true (counter
     * decremented each call), and the (N+1)th call flips it. Useful to
     * exercise the polling loop in `runWithNavidromeStopped()`. Set to
     * a very large number to simulate a Navidrome that never stops.
     */
    private ?int $inspectsRemainingBeforeStopped = null;

    /**
     * @param array<string, mixed>|null $state
     */
    public function __construct(
        private ?array $state = null,
        private readonly ?string $throwOnInspect = null,
    ) {
        parent::__construct();
    }

    public function inspectState(string $containerName): ?array
    {
        if ($this->throwOnInspect !== null) {
            throw new NavidromeContainerException($this->throwOnInspect);
        }

        if ($this->inspectsRemainingBeforeStopped !== null && $this->state !== null) {
            if ($this->inspectsRemainingBeforeStopped > 0) {
                $this->inspectsRemainingBeforeStopped--;
            } else {
                $this->state['Running'] = false;
                $this->inspectsRemainingBeforeStopped = null;
            }
        }

        return $this->state;
    }

    public function start(string $containerName): void
    {
        $this->lastAction = ['start', $containerName];
        $this->actions[] = ['start', $containerName];
        if ($this->startShouldFail) {
            throw new NavidromeContainerException('docker start a échoué (simulé).');
        }
        if ($this->state !== null) {
            $this->state['Running'] = true;
        }
    }

    public function stop(string $containerName, int $timeoutSeconds = 10): void
    {
        $this->lastAction = ['stop', $containerName];
        $this->actions[] = ['stop', $containerName];
        $this->lastStopTimeout = $timeoutSeconds;
        if ($this->stopShouldFail) {
            throw new NavidromeContainerException('docker stop a échoué (simulé).');
        }
        if ($this->inspectsRemainingBeforeStopped !== null) {
            // Slow-shutdown mode: leave Running=true and let inspectState()
            // count down. Don't flip here.
            return;
        }
        if ($this->state !== null) {
            $this->state['Running'] = false;
        }
    }

    public function simulateSlowShutdown(int $inspectsBeforeStopped): void
    {
        $this->inspectsRemainingBeforeStopped = $inspectsBeforeStopped;
    }
}
