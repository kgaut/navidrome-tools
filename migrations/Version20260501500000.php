<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260501500000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add lastfm_import_track table to persist per-scrobble status of each Last.fm import run.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE lastfm_import_track (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                run_history_id INTEGER NOT NULL,
                artist VARCHAR(255) NOT NULL,
                title VARCHAR(255) NOT NULL,
                album VARCHAR(255) DEFAULT NULL,
                mbid VARCHAR(64) DEFAULT NULL,
                played_at DATETIME NOT NULL,
                status VARCHAR(16) NOT NULL,
                matched_media_file_id VARCHAR(255) DEFAULT NULL,
                CONSTRAINT FK_lastfm_import_track_run FOREIGN KEY (run_history_id)
                    REFERENCES run_history (id) ON DELETE CASCADE
            )
        SQL);
        $this->addSql('CREATE INDEX idx_lastfm_import_track_run_status ON lastfm_import_track (run_history_id, status)');
        $this->addSql('CREATE INDEX idx_lastfm_import_track_run_played ON lastfm_import_track (run_history_id, played_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE lastfm_import_track');
    }
}
