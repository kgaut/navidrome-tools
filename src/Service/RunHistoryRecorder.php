<?php

namespace App\Service;

use App\Entity\RunHistory;
use App\Notifier\Notification;
use App\Notifier\Notifier;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

class RunHistoryRecorder
{
    private EntityManagerInterface $em;

    public function __construct(
        private readonly ManagerRegistry $registry,
        EntityManagerInterface $em,
        private readonly ?Notifier $notifier = null,
    ) {
        $this->em = $em;
    }

    /**
     * Wraps an action and records its outcome (success/error) into run_history.
     * Re-throws on error so the caller can surface it to the user.
     *
     * The action callable receives the freshly persisted RunHistory entry
     * (already flushed, so its id is set) — this lets the action attach
     * child entities to the run via foreign key. Existing arrow-fn callers
     * that don't declare the parameter ignore it (PHP silently discards
     * extra positional args).
     *
     * Robustness: when the wrapped action triggers a Doctrine flush failure
     * (constraint violation, deadlock, schema mismatch…), Doctrine *closes*
     * the EntityManager. Without recovery, the catch block's own flush —
     * meant to mark the RunHistory row as errored — would itself throw
     * « The EntityManager is closed », swallowing the original cause. We
     * detect that here, reset the manager via {@see ManagerRegistry}, and
     * re-hydrate the RunHistory entry on the fresh EM so the error trail
     * is still persisted and surfaced. The original exception is re-thrown
     * untouched, so the user sees the real failure.
     *
     * @template T
     *
     * @param callable(RunHistory): T            $action
     * @param ?callable(T): array<string, mixed> $extractMetrics
     *
     * @return T
     */
    public function record(
        string $type,
        string $reference,
        string $label,
        callable $action,
        ?callable $extractMetrics = null,
    ): mixed {
        $entry = new RunHistory($type, $reference, $label);
        $entry->setStatus(RunHistory::STATUS_RUNNING);
        $this->em->persist($entry);
        $this->em->flush();

        $startedMicrotime = microtime(true);

        try {
            $result = $action($entry);
        } catch (\Throwable $e) {
            $entry = $this->refreshEntryAfterFailure($entry);
            $entry->setStatus(RunHistory::STATUS_ERROR);
            $entry->setMessage($e->getMessage());
            $entry->setFinishedAt(new \DateTimeImmutable());
            $entry->setDurationMs((int) round((microtime(true) - $startedMicrotime) * 1000));
            try {
                $this->em->flush();
                $this->notify($entry);
            } catch (\Throwable) {
                // Best-effort error trail: if even the re-armed EM can't
                // flush (DB unreachable, transient I/O…), don't shadow the
                // original exception. The user's primary feedback is the
                // re-thrown $e below.
            }
            throw $e;
        }

        $entry->setStatus(RunHistory::STATUS_SUCCESS);
        $entry->setFinishedAt(new \DateTimeImmutable());
        $entry->setDurationMs((int) round((microtime(true) - $startedMicrotime) * 1000));

        if ($extractMetrics !== null) {
            try {
                $entry->setMetrics($extractMetrics($result));
            } catch (\Throwable) {
                // Swallow metric extraction errors — they should never block a successful run.
            }
        }

        $this->em->flush();
        $this->notify($entry);

        return $result;
    }

    /**
     * If the action's flush closed the EntityManager (typical on constraint
     * violation), the existing $entry instance is detached and unusable.
     * Reset the manager and re-fetch the row so we can update its status
     * cleanly. When the EM is still open we just keep the same entry.
     */
    private function refreshEntryAfterFailure(RunHistory $entry): RunHistory
    {
        if ($this->em->isOpen()) {
            return $entry;
        }

        $id = $entry->getId();
        $this->registry->resetManager();
        $fresh = $this->registry->getManager();
        if (!$fresh instanceof EntityManagerInterface) {
            // Should never happen with the default Doctrine bundle setup,
            // but bail out cleanly: keep the old entry, the outer try/catch
            // around flush() will absorb any subsequent failure.
            return $entry;
        }
        $this->em = $fresh;

        if ($id !== null) {
            $reloaded = $this->em->find(RunHistory::class, $id);
            if ($reloaded instanceof RunHistory) {
                return $reloaded;
            }
        }

        // Couldn't refetch (row deleted? id never assigned because the very
        // first flush failed?). Persist a new pseudo-entry under the same
        // type/reference/label so at least *something* lands in run_history.
        $rebuilt = new RunHistory($entry->getType(), $entry->getReference(), $entry->getLabel());
        $this->em->persist($rebuilt);

        return $rebuilt;
    }

    private function notify(RunHistory $entry): void
    {
        if ($this->notifier === null) {
            return;
        }
        try {
            $this->notifier->notify(Notification::fromRunHistory($entry));
        } catch (\Throwable) {
            // Notification dispatch must never abort the job. The
            // orchestrator already catches per-driver, this is a final
            // safety net.
        }
    }
}
