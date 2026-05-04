<?php

namespace App\Service;

use App\Entity\RunHistory;
use Doctrine\ORM\EntityManagerInterface;

class RunHistoryRecorder
{
    /**
     * Throttles progress flushes to at most once per this many seconds per entry.
     * Avoids hammering SQLite when callbacks fire every 50 items.
     */
    private const PROGRESS_FLUSH_INTERVAL_SECONDS = 1.0;

    /** @var array<int, float> Last flush microtime keyed by RunHistory id. */
    private array $lastProgressFlushAt = [];

    public function __construct(private readonly EntityManagerInterface $em)
    {
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
        $this->em->persist($entry);
        $this->em->flush();

        return $this->recordExisting($entry, $action, $extractMetrics);
    }

    /**
     * Same as record() but reuses an already-persisted RunHistory entry —
     * typically created by the controller (status=queued) and picked up by
     * an async MessageHandler. Bumps status to running, then proceeds with
     * the same success/error bookkeeping as record().
     *
     * @template T
     *
     * @param callable(RunHistory): T            $action
     * @param ?callable(T): array<string, mixed> $extractMetrics
     *
     * @return T
     */
    public function recordExisting(
        RunHistory $entry,
        callable $action,
        ?callable $extractMetrics = null,
    ): mixed {
        $entry->setStatus(RunHistory::STATUS_RUNNING);
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
            $this->forgetProgressFlushTimestamp($entry);
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
        $this->forgetProgressFlushTimestamp($entry);

        return $result;
    }

    /**
     * Updates the running entry's progress payload. Flushes at most once per
     * PROGRESS_FLUSH_INTERVAL_SECONDS to keep SQLite write pressure low when
     * callers tick frequently. The final state is always flushed by
     * recordExisting() at the end.
     */
    public function updateProgress(
        RunHistory $entry,
        int $current,
        ?int $total = null,
        ?string $message = null,
    ): void {
        $percent = null;
        if ($total !== null && $total > 0) {
            $percent = round(min(100.0, ($current / $total) * 100), 1);
        }

        $entry->setProgress([
            'current' => $current,
            'total' => $total,
            'percent' => $percent,
            'message' => $message,
            'updated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);

        $id = $entry->getId();
        if ($id === null) {
            return;
        }

        $now = microtime(true);
        $last = $this->lastProgressFlushAt[$id] ?? 0.0;
        if (($now - $last) < self::PROGRESS_FLUSH_INTERVAL_SECONDS) {
            return;
        }

        $this->lastProgressFlushAt[$id] = $now;
        $this->em->flush();
    }

    private function forgetProgressFlushTimestamp(RunHistory $entry): void
    {
        $id = $entry->getId();
        if ($id !== null) {
            unset($this->lastProgressFlushAt[$id]);
        }
    }
}
