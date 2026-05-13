<?php

namespace App\Service;

use App\Entity\RunHistory;
use App\Notifier\Notification;
use App\Notifier\Notifier;
use Doctrine\ORM\EntityManagerInterface;

class RunHistoryRecorder
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ?Notifier $notifier = null,
    ) {
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
            $entry->setStatus(RunHistory::STATUS_ERROR);
            $entry->setMessage($e->getMessage());
            $entry->setFinishedAt(new \DateTimeImmutable());
            $entry->setDurationMs((int) round((microtime(true) - $startedMicrotime) * 1000));
            $this->em->flush();
            $this->notify($entry);
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
