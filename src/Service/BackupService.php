<?php

namespace App\Service;

/**
 * Creates SQLite database snapshots and prunes old ones.
 *
 * Backup filenames follow the pattern: YYYY-MM-DD_HH-MM-SS_LABEL.sqlite
 * Stored in $backupDir (default var/backups, configured as a Docker volume).
 */
class BackupService
{
    public function __construct(private readonly string $backupDir)
    {
    }

    /**
     * Copy $dbPath to the backup directory. Returns the full path of the
     * created backup file.
     *
     * @throws \RuntimeException if the source file is not readable or the
     *                           copy fails.
     */
    public function backup(string $dbPath, string $label): string
    {
        if (!file_exists($dbPath)) {
            throw new \RuntimeException(sprintf('Database file not found: %s', $dbPath));
        }

        if (!is_dir($this->backupDir)) {
            if (!mkdir($this->backupDir, 0755, true)) {
                throw new \RuntimeException(sprintf('Cannot create backup directory: %s', $this->backupDir));
            }
        }

        $safeLabel = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $label) ?? $label;
        $filename = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d_H-i-s')
            . '_' . $safeLabel . '.sqlite';
        $dest = $this->backupDir . '/' . $filename;

        if (!copy($dbPath, $dest)) {
            throw new \RuntimeException(sprintf('Failed to copy %s to %s', $dbPath, $dest));
        }

        // Also copy WAL and SHM siblings if present.
        foreach (['-wal', '-shm'] as $suffix) {
            $sibling = $dbPath . $suffix;
            if (file_exists($sibling)) {
                copy($sibling, $dest . $suffix);
            }
        }

        return $dest;
    }

    /**
     * Delete backup files older than $days days. Returns the number of
     * files removed.
     */
    public function pruneOlderThan(int $days): int
    {
        if (!is_dir($this->backupDir) || $days <= 0) {
            return 0;
        }

        $cutoff = (new \DateTimeImmutable())->modify(sprintf('-%d days', $days))->getTimestamp();
        $removed = 0;

        foreach (glob($this->backupDir . '/*.sqlite') ?: [] as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
                $removed++;
                // Remove WAL/SHM siblings too.
                foreach (['-wal', '-shm'] as $suffix) {
                    if (file_exists($file . $suffix)) {
                        unlink($file . $suffix);
                    }
                }
            }
        }

        return $removed;
    }

    /**
     * @return list<array{path: string, label: string, size: int, created_at: \DateTimeImmutable}>
     */
    public function listBackups(): array
    {
        if (!is_dir($this->backupDir)) {
            return [];
        }

        $files = glob($this->backupDir . '/*.sqlite') ?: [];
        rsort($files);

        return array_map(static function (string $path): array {
            $name = basename($path, '.sqlite');
            return [
                'path' => $path,
                'label' => $name,
                'size' => (int) filesize($path),
                'created_at' => new \DateTimeImmutable('@' . filemtime($path)),
            ];
        }, $files);
    }
}
