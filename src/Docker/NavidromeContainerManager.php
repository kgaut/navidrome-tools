<?php

namespace App\Docker;

use App\Navidrome\NavidromeDbBackup;

class NavidromeContainerManager
{
    public function __construct(
        private readonly DockerCli $cli,
        private readonly NavidromeContainerConfig $config,
        private readonly NavidromeDbBackup $backup,
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
        $this->cli->stop($this->config->containerName, $this->config->stopTimeoutSeconds);
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
     * block, so an action that throws still leaves Navidrome running —
     * unless the post-action quick_check detects corruption, in which
     * case we restore the pre-action snapshot before restart.
     *
     * Defense in depth around $action():
     *  1. `docker stop -t <NAVIDROME_STOP_TIMEOUT_SECONDS>` — long enough
     *     for Navidrome's SQLite WAL to checkpoint cleanly (default 60s,
     *     vs Docker's 10s default which used to SIGKILL mid-flush and
     *     leave a half-written WAL — the corruption pattern from #118).
     *  2. Poll `docker inspect` until Running=false — we never trust
     *     `docker stop` returning success alone.
     *  3. Snapshot the SQLite file (and its `-wal`/`-shm` siblings) to
     *     `<dbPath>.backup-<ts>` so any future write that goes wrong is
     *     recoverable with a single `cp`. Last 3 by default (configurable).
     *  4. PRE-action `PRAGMA quick_check` — if the DB is already broken
     *     (e.g. from a previous bad shutdown) we abort before writing
     *     into it and making it worse. Skippable via $skipPreCheck for
     *     `app:navidrome:db:restore` which by definition runs on a DB
     *     that's already in trouble.
     *  5. POST-action `PRAGMA quick_check` — catches the case where
     *     $action() crashed mid-write and left the DB inconsistent. If
     *     it fails we automatically restore the pre-action snapshot
     *     (step 3) before restarting Navidrome, so Navidrome never
     *     reopens a corrupt DB.
     *
     * - Feature disabled (`NAVIDROME_CONTAINER_NAME` empty) → run as-is,
     *   skip all the above (we don't know the DB lifecycle).
     * - Status `Stopped` / `NotFound` → still backup + quickCheck before
     *   the action (same risk profile), but don't restart something we
     *   didn't stop.
     * - Status `Running` → stop, wait, backup, check, run, post-check,
     *   restart (always, unless restore failed and DB is in limbo).
     * - Status `Unknown` → throw, since we can't safely orchestrate
     *   stop/start without confirming current state.
     *
     * If both the action and the restart fail, the restart failure is
     * raised with the action error chained as `previous`. If the
     * post-action quickCheck fails AND the restore fails, Navidrome is
     * NOT restarted (DB in unknown state) and the user is told to run
     * `app:navidrome:db:restore` manually.
     *
     * @template T
     * @param  callable(): T $action
     * @param  bool $skipPreCheck Skip the pre-action `PRAGMA quick_check`
     *                            — reserved for `app:navidrome:db:restore`
     *                            which needs to operate on a DB that the
     *                            check would refuse to open.
     * @return T
     */
    public function runWithNavidromeStopped(callable $action, bool $skipPreCheck = false): mixed
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

        $weStoppedIt = false;
        if ($status === ContainerStatus::Running) {
            $this->stop();
            $weStoppedIt = true;
            $this->waitUntilStopped($this->config->stopWaitCeilingSeconds);
        }

        // Backup + quick_check apply whether we stopped Navidrome ourselves
        // or it was already down — either way we're about to mutate the
        // file and we want a rollback artefact + a sanity check first.
        $this->backup->backup();

        $actionException = null;
        $actionRan = false;
        $result = null;
        try {
            if (!$skipPreCheck) {
                $this->backup->quickCheck();
            }
            $actionRan = true;
            $result = $action();
        } catch (\Throwable $e) {
            $actionException = $e;
        }

        // Post-action quickCheck only if the action actually ran. When the
        // pre-check failed, the DB was already corrupt before our action,
        // there's no new information to gather and we shouldn't try to
        // overwrite the existing state.
        $postCheckFailure = null;
        if ($actionRan) {
            try {
                $this->backup->quickCheck();
            } catch (\Throwable $e) {
                $postCheckFailure = $e;
            }
        }

        if ($postCheckFailure !== null) {
            return $this->handlePostActionCorruption(
                actionException: $actionException,
                postCheckFailure: $postCheckFailure,
                weStoppedIt: $weStoppedIt,
            );
        }

        if ($weStoppedIt) {
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
        }

        if ($actionException !== null) {
            throw $actionException;
        }

        return $result;
    }

    /**
     * Reached when the post-action quick_check detected corruption. We
     * always try to restore the pre-action snapshot before letting
     * Navidrome reopen the file. Two terminal states :
     *  - restore OK + restart OK → throw an explicit corruption error so
     *    the user knows the action was rolled back ;
     *  - restore failed → throw compound error, do NOT restart Navidrome
     *    (it would reopen a broken DB and propagate the corruption).
     *
     * Always throws ; the `mixed` return type just satisfies the parent
     * method's signature.
     */
    private function handlePostActionCorruption(
        ?\Throwable $actionException,
        \Throwable $postCheckFailure,
        bool $weStoppedIt,
    ): mixed {
        try {
            $restoredFrom = $this->backup->restore(null);
        } catch (\Throwable $restoreException) {
            throw new NavidromeContainerException(
                sprintf(
                    'DB Navidrome corrompue après l\'action ET la restore automatique a échoué : %s. Restaurez manuellement (`app:navidrome:db:restore`) avant de redémarrer Navidrome.',
                    $restoreException->getMessage(),
                ),
                0,
                $actionException ?? $postCheckFailure,
            );
        }

        if ($weStoppedIt) {
            try {
                $this->start();
            } catch (\Throwable $startException) {
                throw new NavidromeContainerException(
                    sprintf(
                        'DB Navidrome corrompue après l\'action — restaurée depuis %s, mais le redémarrage du conteneur a échoué : %s',
                        $restoredFrom,
                        $startException->getMessage(),
                    ),
                    0,
                    $actionException ?? $postCheckFailure,
                );
            }
        }

        $actionDetail = $actionException !== null
            ? sprintf(' Erreur d\'origine de l\'action : %s', $actionException->getMessage())
            : '';

        throw new NavidromeContainerException(
            sprintf(
                'DB Navidrome corrompue après l\'action — restaurée automatiquement depuis %s.%s',
                $restoredFrom,
                $actionDetail,
            ),
            0,
            $actionException ?? $postCheckFailure,
        );
    }

    /**
     * Block until `docker inspect` reports Running=false, or the ceiling
     * (in seconds) is reached. The first probe runs immediately so a
     * cleanly-stopped container exits without a sleep ; subsequent probes
     * are spaced 500ms apart. Throws on ceiling so the caller never
     * proceeds to write while Navidrome is still alive.
     */
    private function waitUntilStopped(int $maxSeconds): void
    {
        $deadline = microtime(true) + max(1, $maxSeconds);

        while (true) {
            try {
                $state = $this->cli->inspectState($this->config->containerName);
            } catch (NavidromeContainerException $e) {
                throw new NavidromeContainerException(sprintf(
                    'Impossible de confirmer l\'arrêt du conteneur Navidrome « %s » : %s. Abandon avant écriture pour éviter la corruption.',
                    $this->config->containerName,
                    $e->getMessage(),
                ), 0, $e);
            }

            // null = container vanished (compose down) ; Running:false = stopped.
            if ($state === null || ($state['Running'] ?? null) !== true) {
                return;
            }

            if (microtime(true) >= $deadline) {
                throw new NavidromeContainerException(sprintf(
                    'Le conteneur Navidrome « %s » est toujours en cours %ds après `docker stop`. Abandon avant écriture pour éviter la corruption SQLite.',
                    $this->config->containerName,
                    $maxSeconds,
                ));
            }

            usleep(500_000);
        }
    }
}
