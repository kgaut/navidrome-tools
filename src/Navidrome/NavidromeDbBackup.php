<?php

namespace App\Navidrome;

/**
 * Safety net around the Navidrome SQLite file. Three responsibilities:
 *
 *  1. {@see backup()} copies `<dbPath>` (and its `-wal` / `-shm` siblings
 *     when present) to `<dbPath>.backup-<unix_ts>` just before any
 *     write-mutating operation (`app:lastfm:process --auto-stop`,
 *     `app:lastfm:rematch --auto-stop`, etc.). Old backups beyond
 *     `$retention` are pruned.
 *  2. {@see quickCheck()} opens the file SQLite read-only and runs
 *     `PRAGMA quick_check` — a lightweight structural sanity test that
 *     catches a half-flushed WAL or a SIGKILL-truncated header *before*
 *     we open the DB for writes (which would aggravate the corruption).
 *  3. {@see restore()} copies a previously-taken backup back over the
 *     live DB (with sibling WAL/SHM handling). Sanity-checks the backup
 *     first to refuse a zombie restore. Caller must ensure Navidrome is
 *     stopped — this class does no orchestration.
 *
 * Used by `NavidromeContainerManager::runWithNavidromeStopped()` for the
 * pre-action snapshot + post-action restore-on-corruption, and by
 * `app:navidrome:db:restore` for manual rollbacks. Not a Doctrine
 * connection: stays out of the DBAL UDF setup (`np_normalize`) so it can
 * run on a potentially broken file without dragging the whole
 * repository's machinery in.
 */
class NavidromeDbBackup
{
    public function __construct(
        private readonly string $dbPath,
        private readonly int $retention = 3,
    ) {
    }

    /**
     * Returns the path of the backup just created, or null when the source
     * file does not exist (Navidrome never started, fresh install — the
     * caller can decide to skip rather than fail).
     */
    public function backup(): ?string
    {
        if (!is_file($this->dbPath)) {
            return null;
        }

        $timestamp = (new \DateTimeImmutable())->format('YmdHis');
        $target = $this->dbPath . '.backup-' . $timestamp;

        $this->copyAtomic($this->dbPath, $target);
        // Copy WAL/SHM siblings so the restore replicates the *exact* state
        // SQLite was in. Missing siblings = clean DB, nothing to copy.
        foreach (['-wal', '-shm'] as $suffix) {
            $sibling = $this->dbPath . $suffix;
            if (is_file($sibling)) {
                $this->copyAtomic($sibling, $target . $suffix);
            }
        }

        $this->prune();

        return $target;
    }

    /**
     * Throws when the live DB fails the structural sanity test.
     * No-op when the file is missing (treated as « nothing to check »).
     */
    public function quickCheck(): void
    {
        if (!is_file($this->dbPath)) {
            return;
        }

        $this->quickCheckFile($this->dbPath);
    }

    /**
     * Copies a previously-taken backup over the live DB. Verifies the
     * backup is itself sound before clobbering anything — restoring a
     * corrupted snapshot would be worse than the situation we're trying
     * to recover from. Handles WAL/SHM siblings:
     *  - if the backup has them, restore them too;
     *  - if it doesn't, wipe any live siblings so SQLite doesn't try to
     *    replay a stale WAL on top of the restored main DB.
     *
     * @param string|null $timestamp Backup timestamp `YYYYMMDDHHMMSS`.
     *                               `null` = use the most recent backup.
     * @return string Path of the backup that was used as the source.
     * @throws \RuntimeException If no backup matches, the backup itself
     *                           fails quick_check, or the copy/rename
     *                           fails. The live DB is left untouched on
     *                           any pre-write failure.
     */
    public function restore(?string $timestamp = null): string
    {
        $backupPath = $this->resolveBackup($timestamp);

        // Sanity-check the backup BEFORE clobbering the live DB.
        $this->quickCheckFile($backupPath);

        $this->copyAtomic($backupPath, $this->dbPath);

        // Sync siblings: the backup either has them (replicate) or
        // doesn't (drop the live ones to avoid a stale-WAL replay).
        foreach (['-wal', '-shm'] as $suffix) {
            $backupSibling = $backupPath . $suffix;
            $liveSibling = $this->dbPath . $suffix;
            if (is_file($backupSibling)) {
                $this->copyAtomic($backupSibling, $liveSibling);
            } elseif (is_file($liveSibling)) {
                @unlink($liveSibling);
            }
        }

        // Confirm the live DB is sound after restore. If something went
        // wrong (FS issue, partial copy), surface it now rather than at
        // the next Navidrome start.
        $this->quickCheck();

        return $backupPath;
    }

    /**
     * @return list<string> backup paths sorted from oldest to newest
     */
    public function listBackups(): array
    {
        $matches = glob($this->dbPath . '.backup-*') ?: [];
        // Drop the -wal / -shm siblings — we only want the main backup files
        // for retention bookkeeping.
        $main = array_values(array_filter(
            $matches,
            static fn (string $p): bool => preg_match('/\.backup-\d+$/', $p) === 1,
        ));
        sort($main);

        return $main;
    }

    /**
     * Returns the timestamp (`YYYYMMDDHHMMSS`) of the most recent backup,
     * or `null` if none exist. Convenience for CLI listings.
     */
    public function latestBackup(): ?string
    {
        $backups = $this->listBackups();
        if ($backups === []) {
            return null;
        }
        $path = end($backups);
        if (preg_match('/\.backup-(\d+)$/', $path, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    private function resolveBackup(?string $timestamp): string
    {
        if ($timestamp !== null) {
            $candidate = $this->dbPath . '.backup-' . $timestamp;
            if (!is_file($candidate)) {
                throw new \RuntimeException(sprintf(
                    'Aucun backup trouvé pour le timestamp « %s » (cherché : %s).',
                    $timestamp,
                    $candidate,
                ));
            }
            return $candidate;
        }

        $backups = $this->listBackups();
        if ($backups === []) {
            throw new \RuntimeException(sprintf(
                'Aucun backup disponible à côté de %s.',
                $this->dbPath,
            ));
        }

        return end($backups);
    }

    private function quickCheckFile(string $path): void
    {
        try {
            // URI form `mode=ro` opens the DB strictly read-only — without
            // it, PDO defaults to RW which would have SQLite truncate or
            // delete an unrecognized -wal sibling (we hit this with fake
            // siblings during backup-restore round-trips). Read-only also
            // suffices for `PRAGMA quick_check`.
            $pdo = new \PDO('sqlite:file:' . $path . '?mode=ro', null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
            $stmt = $pdo->query('PRAGMA quick_check');
            $result = $stmt !== false ? $stmt->fetchColumn() : false;
        } catch (\PDOException $e) {
            throw new \RuntimeException(sprintf(
                'Le fichier SQLite %s est illisible : %s.',
                $path,
                $e->getMessage(),
            ), 0, $e);
        }

        if ($result !== 'ok') {
            throw new \RuntimeException(sprintf(
                'PRAGMA quick_check a échoué sur %s : « %s ».',
                $path,
                is_string($result) ? $result : 'résultat inattendu',
            ));
        }
    }

    private function prune(): void
    {
        if ($this->retention <= 0) {
            return;
        }

        $backups = $this->listBackups();
        $excess = count($backups) - $this->retention;
        if ($excess <= 0) {
            return;
        }

        foreach (array_slice($backups, 0, $excess) as $stale) {
            @unlink($stale);
            @unlink($stale . '-wal');
            @unlink($stale . '-shm');
        }
    }

    private function copyAtomic(string $source, string $target): void
    {
        $tmp = $target . '.tmp';
        if (!@copy($source, $tmp)) {
            throw new \RuntimeException(sprintf('Backup impossible : copie %s → %s a échoué.', $source, $tmp));
        }
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            throw new \RuntimeException(sprintf('Backup impossible : rename %s → %s a échoué.', $tmp, $target));
        }
    }
}
