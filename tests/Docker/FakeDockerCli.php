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
        if ($this->stopShouldFail) {
            throw new NavidromeContainerException('docker stop a échoué (simulé).');
        }
        if ($this->state !== null) {
            $this->state['Running'] = false;
        }
    }
}
