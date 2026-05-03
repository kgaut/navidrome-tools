<?php

namespace App\Docker;

use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;

/**
 * Thin wrapper around `docker` CLI invocations. Made non-final so tests can
 * override `runProcess()` with a fake — the manager is the unit of behaviour
 * we care about, the CLI binary is a transport detail.
 */
class DockerCli
{
    public function __construct(
        private readonly string $binary = 'docker',
    ) {
    }

    /**
     * @return array<string, mixed>|null  null when the container does not exist
     */
    public function inspectState(string $containerName): ?array
    {
        $result = $this->runProcess(
            [$this->binary, 'inspect', $containerName, '--format', '{{json .State}}'],
            5,
        );

        if ($result['exitCode'] !== 0) {
            $stderr = $result['stderr'];
            // Docker prints "Error: No such object: <name>" when the container is unknown.
            if (stripos($stderr, 'no such object') !== false) {
                return null;
            }
            throw new NavidromeContainerException(sprintf(
                'docker inspect a échoué (code %d) : %s',
                $result['exitCode'],
                trim($stderr) !== '' ? trim($stderr) : trim($result['stdout']),
            ));
        }

        $decoded = json_decode(trim($result['stdout']), true);
        if (!is_array($decoded)) {
            throw new NavidromeContainerException('Sortie JSON inattendue depuis `docker inspect`.');
        }

        return $decoded;
    }

    public function start(string $containerName): void
    {
        $result = $this->runProcess([$this->binary, 'start', $containerName], 30);
        if ($result['exitCode'] !== 0) {
            throw new NavidromeContainerException(sprintf(
                'docker start a échoué : %s',
                trim($result['stderr']) !== '' ? trim($result['stderr']) : trim($result['stdout']),
            ));
        }
    }

    public function stop(string $containerName, int $timeoutSeconds = 10): void
    {
        $result = $this->runProcess(
            [$this->binary, 'stop', '-t', (string) $timeoutSeconds, $containerName],
            max(30, $timeoutSeconds + 20),
        );
        if ($result['exitCode'] !== 0) {
            throw new NavidromeContainerException(sprintf(
                'docker stop a échoué : %s',
                trim($result['stderr']) !== '' ? trim($result['stderr']) : trim($result['stdout']),
            ));
        }
    }

    /**
     * @param  list<string>                                      $command
     * @return array{exitCode: int, stdout: string, stderr: string}
     */
    protected function runProcess(array $command, int $timeoutSeconds): array
    {
        $process = new Process($command);
        $process->setTimeout($timeoutSeconds);
        try {
            $process->run();
        } catch (ProcessRuntimeException $e) {
            // Most common: the docker binary is missing from PATH.
            throw new NavidromeContainerException(sprintf(
                'Impossible d\'exécuter `%s` : %s',
                $command[0] ?? 'docker',
                $e->getMessage(),
            ), 0, $e);
        }

        return [
            'exitCode' => $process->getExitCode() ?? -1,
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }
}
