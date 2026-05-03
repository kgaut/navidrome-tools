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
}
