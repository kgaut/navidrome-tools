<?php

namespace App\Service;

use App\Entity\RunHistory;

/**
 * Appends absolute file paths to a queue file consumed by an external beets
 * cron — the file is the only RW resource navidrome-tools needs ; /music
 * stays read-only on this side. Concurrent writers and the beets-side
 * consumer coexist safely thanks to advisory file locks (`flock`).
 *
 * Format : one absolute path per line, UTF-8, no escaping (paths with
 * embedded newlines aren't supported — neither beets nor most filesystems
 * support them anyway). Duplicate suppression is delegated to the beets
 * `import.incremental` flag — pushing a path twice is harmless.
 *
 * Recommended cron on the beets side (atomic mv + flock) :
 *
 *     ( flock -x 9
 *       [ -s /shared/queue.txt ] || exit 0
 *       mv /shared/queue.txt /shared/queue.processing
 *     ) 9>/shared/queue.lock
 *     beet import -A --quiet $(cat /shared/queue.processing)
 *     rm /shared/queue.processing
 *
 * The shell only holds the flock for the rename ; navidrome-tools then
 * starts appending to a fresh file while beets is still running.
 */
class BeetsQueueService
{
    public function __construct(
        private readonly string $queuePath,
        private readonly RunHistoryRecorder $recorder,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->queuePath !== '';
    }

    /**
     * Current size of the queue file, in lines. Returns null when the
     * service isn't configured or the file doesn't exist yet (— the page
     * shows that as "queue empty"). Counts lines without loading the whole
     * file in memory.
     */
    public function pendingCount(): ?int
    {
        if (!$this->isConfigured() || !is_file($this->queuePath)) {
            return null;
        }

        $count = 0;
        $fh = @fopen($this->queuePath, 'rb');
        if ($fh === false) {
            return null;
        }
        try {
            while (!feof($fh)) {
                $line = fgets($fh);
                if ($line === false) {
                    break;
                }
                if (trim($line) !== '') {
                    ++$count;
                }
            }
        } finally {
            fclose($fh);
        }

        return $count;
    }

    /**
     * Append the given paths to the queue file under an exclusive flock,
     * recording the push as a {@see RunHistory} row (type beets-queue-push).
     *
     * Empty paths and paths that contain a newline are silently skipped —
     * the beets cron consumes one-line-one-path so embedded newlines would
     * corrupt the queue.
     *
     * @param string[] $paths absolute filesystem paths
     */
    public function push(array $paths, string $reason = 'manual'): RunHistory
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Beets queue is not configured (BEETS_QUEUE_PATH).');
        }

        return $this->recorder->record(
            type: RunHistory::TYPE_BEETS_QUEUE_PUSH,
            reference: $reason,
            label: sprintf('Push to beets queue (%d candidate%s)', count($paths), count($paths) > 1 ? 's' : ''),
            action: function (RunHistory $entry) use ($paths, $reason): RunHistory {
                $clean = [];
                $skipped = 0;
                foreach ($paths as $p) {
                    $p = (string) $p;
                    if ($p === '' || str_contains($p, "\n") || str_contains($p, "\r")) {
                        ++$skipped;
                        continue;
                    }
                    $clean[] = $p;
                }

                $entry->setMetrics([
                    'reason' => $reason,
                    'queue_path' => $this->queuePath,
                    'submitted' => 0,
                    'skipped_invalid' => $skipped,
                ]);

                if ($clean === []) {
                    return $entry;
                }

                self::ensureDirectory($this->queuePath);

                $fh = @fopen($this->queuePath, 'ab');
                if ($fh === false) {
                    throw new \RuntimeException(sprintf('Cannot open beets queue %s for append.', $this->queuePath));
                }

                try {
                    if (!flock($fh, LOCK_EX)) {
                        throw new \RuntimeException(sprintf('Cannot lock beets queue %s.', $this->queuePath));
                    }
                    $payload = implode("\n", $clean) . "\n";
                    $written = fwrite($fh, $payload);
                    fflush($fh);
                    flock($fh, LOCK_UN);

                    if ($written === false || $written < strlen($payload)) {
                        throw new \RuntimeException(sprintf('Short write on beets queue %s.', $this->queuePath));
                    }
                } finally {
                    fclose($fh);
                }

                $entry->setMetrics([
                    'reason' => $reason,
                    'queue_path' => $this->queuePath,
                    'submitted' => count($clean),
                    'skipped_invalid' => $skipped,
                ]);

                return $entry;
            },
        );
    }

    private static function ensureDirectory(string $filePath): void
    {
        $dir = dirname($filePath);
        if ($dir !== '' && !is_dir($dir) && !@mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create beets queue directory %s.', $dir));
        }
    }
}
