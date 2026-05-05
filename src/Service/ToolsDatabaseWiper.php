<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Clears the local tools database of all import / audit / cache / snapshot
 * data while preserving user-curated content: settings, playlist
 * definitions, track-level Last.fm aliases (lastfm_alias) and artist-level
 * aliases (lastfm_artist_alias).
 */
class ToolsDatabaseWiper
{
    /**
     * Tables wiped, in FK-safe order (children before parents).
     */
    private const TABLES_TO_WIPE = [
        'lastfm_import_track',
        'lastfm_import_buffer',
        'lastfm_match_cache',
        'lastfm_history',
        'navidrome_history',
        'stats_snapshot',
        'top_snapshot',
        'run_history',
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<string, int> table name → number of rows deleted
     */
    public function wipe(): array
    {
        $deleted = [];
        foreach (self::TABLES_TO_WIPE as $table) {
            $deleted[$table] = (int) $this->connection->executeStatement('DELETE FROM ' . $table);
        }
        return $deleted;
    }

    /**
     * @return list<string>
     */
    public static function wipedTables(): array
    {
        return self::TABLES_TO_WIPE;
    }
}
