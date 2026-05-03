<?php

namespace App\Tests\Docker;

use App\Docker\DockerCli;
use App\Docker\NavidromeContainerException;

/**
 * Test double for DockerCli — never spawns a process. Captures the last
 * start/stop call so tests can assert it, and lets each test pre-program
 * the inspect outcome (a state array, null = "not found", or a thrown
 * exception simulating a Docker daemon failure).
 */
final class FakeDockerCli extends DockerCli
{
    /** @var array{0: string, 1: string}|null */
    public ?array $lastAction = null;

    /**
     * @param array<string, mixed>|null $state
     */
    public function __construct(
        private readonly ?array $state = null,
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
    }

    public function stop(string $containerName, int $timeoutSeconds = 10): void
    {
        $this->lastAction = ['stop', $containerName];
    }
}
