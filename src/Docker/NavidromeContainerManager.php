<?php

namespace App\Docker;

class NavidromeContainerManager
{
    public function __construct(
        private readonly DockerCli $cli,
        private readonly NavidromeContainerConfig $config,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->config->isConfigured();
    }

    public function getStatus(): ContainerStatus
    {
        if (!$this->config->isConfigured()) {
            return ContainerStatus::Disabled;
        }

        try {
            $state = $this->cli->inspectState($this->config->containerName);
        } catch (NavidromeContainerException) {
            return ContainerStatus::Unknown;
        }

        if ($state === null) {
            return ContainerStatus::NotFound;
        }

        $running = $state['Running'] ?? null;
        if ($running === true) {
            return ContainerStatus::Running;
        }

        return ContainerStatus::Stopped;
    }

    public function start(): void
    {
        if (!$this->config->isConfigured()) {
            throw new NavidromeContainerException('NAVIDROME_CONTAINER_NAME n\'est pas renseignée.');
        }
        $this->cli->start($this->config->containerName);
    }

    public function stop(): void
    {
        if (!$this->config->isConfigured()) {
            throw new NavidromeContainerException('NAVIDROME_CONTAINER_NAME n\'est pas renseignée.');
        }
        $this->cli->stop($this->config->containerName);
    }

    /**
     * Throws when it would be unsafe to write to the Navidrome database.
     *
     * - Container running → always unsafe (unless $force).
     * - Container status unknown (socket missing, daemon down…) → unsafe by
     *   default, since we can't confirm Navidrome is stopped.
     * - Container stopped / not found / feature disabled → safe.
     */
    public function assertSafeToWrite(bool $force = false): void
    {
        if ($force) {
            return;
        }

        $status = $this->getStatus();
        if ($status->isSafeToWrite()) {
            return;
        }

        $name = $this->config->containerName;
        $message = match ($status) {
            ContainerStatus::Running => sprintf(
                'Le conteneur Navidrome « %s » tourne actuellement. Arrêtez-le depuis le dashboard ou via `docker stop %s` avant de lancer une opération qui écrit dans la DB Navidrome.',
                $name,
                $name,
            ),
            ContainerStatus::Unknown => sprintf(
                'Impossible de vérifier l\'état du conteneur Navidrome « %s » (socket Docker non monté ou inaccessible). Par sécurité l\'opération est bloquée. Utilisez --force en CLI pour outrepasser, ou videz NAVIDROME_CONTAINER_NAME pour désactiver le check.',
                $name,
            ),
            default => 'Pré-flight Navidrome impossible.',
        };

        throw new NavidromeContainerException($message);
    }

    /**
     * Run $action with Navidrome guaranteed stopped, then restart it if
     * we stopped it ourselves. Restart happens in a finally-equivalent
     * block, so an action that throws still leaves Navidrome running.
     *
     * - Feature disabled (`NAVIDROME_CONTAINER_NAME` empty) → run as-is.
     * - Status `Stopped` / `NotFound` → run as-is, don't try to restart
     *   something we didn't stop.
     * - Status `Running` → stop, run, restart (always).
     * - Status `Unknown` → throw, since we can't safely orchestrate
     *   stop/start without confirming current state.
     *
     * If both the action and the restart fail, the restart failure is
     * raised with the action error chained as `previous`.
     *
     * @template T
     * @param  callable(): T $action
     * @return T
     */
    public function runWithNavidromeStopped(callable $action): mixed
    {
        if (!$this->config->isConfigured()) {
            return $action();
        }

        $status = $this->getStatus();
        if ($status === ContainerStatus::Unknown) {
            throw new NavidromeContainerException(sprintf(
                'Statut du conteneur Navidrome « %s » indéterminé — impossible d\'orchestrer un stop/restart automatique. Vérifier le mount /var/run/docker.sock.',
                $this->config->containerName,
            ));
        }

        if ($status !== ContainerStatus::Running) {
            return $action();
        }

        $this->stop();

        $actionException = null;
        $result = null;
        try {
            $result = $action();
        } catch (\Throwable $e) {
            $actionException = $e;
        }

        try {
            $this->start();
        } catch (\Throwable $startException) {
            if ($actionException !== null) {
                throw new NavidromeContainerException(
                    sprintf(
                        '%s — et le redémarrage du conteneur Navidrome a aussi échoué : %s',
                        $actionException->getMessage(),
                        $startException->getMessage(),
                    ),
                    0,
                    $actionException,
                );
            }
            throw $startException;
        }

        if ($actionException !== null) {
            throw $actionException;
        }

        return $result;
    }
}
