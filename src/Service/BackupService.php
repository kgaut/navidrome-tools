<?php

namespace App\Service;

/**
 * Snapshot SQLite databases to gzipped files. Used for both the tool's
 * own DB (via {@see \App\Command\BackupDataDbCommand}) and the Navidrome
 * library DB (via {@see \App\Command\BackupNavidromeDbCommand}, with
 * stop/start of the Navidrome container around it).
 *
 * Uses `VACUUM INTO` rather than a raw file copy so the snapshot is :
 *   - atomic (single SQL statement, no WAL races) ;
 *   - defragmented (auto-compacted, smaller .db.gz on disk) ;
 *   - portable (no .db-wal / .db-shm side files to worry about).
 *
 * The Alpine image ships neither the `gzip` CLI nor the `sqlite3` CLI,
 * so we go through PHP zlib + PDO directly.
 */
class BackupService
{
    /**
     * Take a gzipped snapshot of $sourcePath into $destinationDir, named
     * `{$prefix}-{YYYY-MM-DD}-{HHMMSS}.db.gz`. Creates $destinationDir
     * if missing. Returns `['path' => …, 'size' => bytes]` of the final
     * gzipped file.
     *
     * @return array{path: string, size: int}
     */
    public function backupSqlite(string $sourcePath, string $destinationDir, string $prefix): array
    {
        if (!is_file($sourcePath)) {
            throw new \RuntimeException(sprintf('Source SQLite database not found: %s', $sourcePath));
        }
        if (!is_readable($sourcePath)) {
            throw new \RuntimeException(sprintf('Source SQLite database is not readable: %s', $sourcePath));
        }

        if (!is_dir($destinationDir) && !@mkdir($destinationDir, 0o755, true) && !is_dir($destinationDir)) {
            throw new \RuntimeException(sprintf('Cannot create backup directory: %s', $destinationDir));
        }

        $stamp = (new \DateTimeImmutable())->format('Y-m-d-His');
        $tempPath = rtrim($destinationDir, '/') . '/' . $prefix . '-' . $stamp . '.db';
        $finalPath = $tempPath . '.gz';

        if (file_exists($tempPath) || file_exists($finalPath)) {
            throw new \RuntimeException(sprintf('Backup target already exists: %s', $finalPath));
        }

        // Open the source DB and run VACUUM INTO. The destination path is
        // interpolated into the SQL (no parameter binding for VACUUM INTO),
        // so we double single quotes to neutralise any apostrophe.
        try {
            $pdo = new \PDO('sqlite:' . $sourcePath, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
            $escaped = str_replace("'", "''", $tempPath);
            $pdo->exec("VACUUM INTO '" . $escaped . "'");
            $pdo = null;
        } catch (\Throwable $e) {
            // If VACUUM partially wrote the file before failing, clean up.
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
            throw new \RuntimeException(sprintf(
                'VACUUM INTO failed for %s: %s',
                $sourcePath,
                $e->getMessage(),
            ), 0, $e);
        }

        try {
            $this->gzipFile($tempPath, $finalPath);
        } finally {
            // The uncompressed snapshot has done its job — we only keep the gz.
            @unlink($tempPath);
        }

        $size = (int) @filesize($finalPath);
        if ($size === 0) {
            throw new \RuntimeException(sprintf('Backup file is empty: %s', $finalPath));
        }

        return ['path' => $finalPath, 'size' => $size];
    }

    /**
     * List `*.db.gz` files in $directory matching $prefix, newest first.
     *
     * @return list<array{path: string, name: string, size: int, mtime: int}>
     */
    public function listBackups(string $directory, string $prefix): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $entries = [];
        foreach (glob(rtrim($directory, '/') . '/' . $prefix . '-*.db.gz') ?: [] as $path) {
            $entries[] = [
                'path' => $path,
                'name' => basename($path),
                'size' => (int) @filesize($path),
                'mtime' => (int) @filemtime($path),
            ];
        }

        usort($entries, static fn (array $a, array $b) => $b['mtime'] <=> $a['mtime']);

        return $entries;
    }

    /**
     * Delete backups older than $retentionDays. Returns the number of
     * files removed. A retention of 0 (or negative) is treated as
     * « keep forever » — no-op.
     */
    public function pruneOlderThan(string $directory, string $prefix, int $retentionDays): int
    {
        if ($retentionDays <= 0) {
            return 0;
        }

        $cutoff = (new \DateTimeImmutable())->modify('-' . $retentionDays . ' days')->getTimestamp();
        $deleted = 0;
        foreach ($this->listBackups($directory, $prefix) as $entry) {
            if ($entry['mtime'] < $cutoff && @unlink($entry['path'])) {
                $deleted++;
            }
        }

        return $deleted;
    }

    private function gzipFile(string $source, string $destination): void
    {
        $in = @fopen($source, 'rb');
        if ($in === false) {
            throw new \RuntimeException(sprintf('Cannot read snapshot file: %s', $source));
        }

        $gz = @gzopen($destination, 'wb9');
        if ($gz === false) {
            fclose($in);
            throw new \RuntimeException(sprintf('Cannot open gzip target for writing: %s', $destination));
        }

        try {
            while (!feof($in)) {
                $chunk = fread($in, 64 * 1024);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $written = gzwrite($gz, $chunk);
                if ($written === 0 || $written === false) {
                    throw new \RuntimeException(sprintf('Gzip write failed for %s', $destination));
                }
            }
        } finally {
            fclose($in);
            gzclose($gz);
        }
    }
}
