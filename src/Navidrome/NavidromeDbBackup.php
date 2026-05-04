<?php

namespace App\Navidrome;

/**
 * Safety net around the Navidrome SQLite file. Two responsibilities:
 *
 *  1. {@see backup()} copies `<dbPath>` (and its `-wal` / `-shm` siblings
 *     when present) to `<dbPath>.backup-<unix_ts>` just before any
 *     write-mutating operation (`app:lastfm:import --auto-stop`, etc.).
 *     Old backups beyond `$retention` are pruned.
 *  2. {@see quickCheck()} opens the file SQLite read-only and runs
 *     `PRAGMA quick_check` — a lightweight structural sanity test that
 *     catches a half-flushed WAL or a SIGKILL-truncated header *before*
 *     we open the DB for writes (which would aggravate the corruption).
 *
 * Only used by `NavidromeContainerManager::runWithNavidromeStopped()` so
 * far. Not a Doctrine connection: stays out of the DBAL UDF setup
 * (`np_normalize`) so it can run on a potentially broken file without
 * dragging the whole repository's machinery in.
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
     * Throws when the SQLite file fails the structural sanity test.
     * No-op when the file is missing (treated as « nothing to check »).
     */
    public function quickCheck(): void
    {
        if (!is_file($this->dbPath)) {
            return;
        }

        try {
            $pdo = new \PDO('sqlite:' . $this->dbPath, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
            // Read-only intent: SQLite still mounts the WAL on open and
            // recovers any clean-but-unmerged frames, which is desirable
            // here (this is also how we « heal » a soft-stop residue).
            $stmt = $pdo->query('PRAGMA quick_check');
            $result = $stmt !== false ? $stmt->fetchColumn() : false;
        } catch (\PDOException $e) {
            throw new \RuntimeException(sprintf(
                'La base SQLite Navidrome (%s) est illisible : %s. Restaurez un backup avant toute écriture.',
                $this->dbPath,
                $e->getMessage(),
            ), 0, $e);
        }

        if ($result !== 'ok') {
            throw new \RuntimeException(sprintf(
                'PRAGMA quick_check a échoué sur %s : « %s ». La DB est corrompue, on bloque l\'écriture pour ne pas aggraver. Restaurez un backup.',
                $this->dbPath,
                is_string($result) ? $result : 'résultat inattendu',
            ));
        }
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
