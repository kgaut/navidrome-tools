<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260514000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add synced_navidrome + synced_strawberry flags to lastfm_import_buffer: buffer rows are now kept as a persistent log instead of being deleted after Navidrome processing.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE lastfm_import_buffer ADD COLUMN synced_navidrome BOOLEAN NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE lastfm_import_buffer ADD COLUMN synced_strawberry BOOLEAN NOT NULL DEFAULT 0');
        $this->addSql(
            'CREATE INDEX idx_lastfm_buffer_sync_state '
            . 'ON lastfm_import_buffer (synced_navidrome, synced_strawberry, played_at)'
        );
    }

    public function down(Schema $schema): void
    {
        // SQLite does not support DROP COLUMN before 3.35 — rebuild the table.
        $this->addSql(<<<'SQL'
            CREATE TABLE lastfm_import_buffer_backup (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                lastfm_user VARCHAR(255) NOT NULL,
                artist VARCHAR(255) NOT NULL,
                title VARCHAR(255) NOT NULL,
                album VARCHAR(255) DEFAULT NULL,
                mbid VARCHAR(64) DEFAULT NULL,
                played_at DATETIME NOT NULL,
                fetched_at DATETIME NOT NULL
            )
        SQL);
        $this->addSql(
            'INSERT INTO lastfm_import_buffer_backup '
            . 'SELECT id, lastfm_user, artist, title, album, mbid, played_at, fetched_at '
            . 'FROM lastfm_import_buffer'
        );
        $this->addSql('DROP TABLE lastfm_import_buffer');
        $this->addSql('ALTER TABLE lastfm_import_buffer_backup RENAME TO lastfm_import_buffer');
        $this->addSql(
            'CREATE UNIQUE INDEX uniq_lastfm_import_buffer_user_played_track '
            . 'ON lastfm_import_buffer (lastfm_user, played_at, artist, title)'
        );
        $this->addSql(
            'CREATE INDEX idx_lastfm_import_buffer_user_played '
            . 'ON lastfm_import_buffer (lastfm_user, played_at)'
        );
    }
}
