<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260504000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add lastfm_import_buffer table: scrobbles fetched from Last.fm pending matching/insertion into Navidrome.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE lastfm_import_buffer (
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
            'CREATE UNIQUE INDEX uniq_lastfm_import_buffer_user_played_track '
            . 'ON lastfm_import_buffer (lastfm_user, played_at, artist, title)'
        );
        $this->addSql(
            'CREATE INDEX idx_lastfm_import_buffer_user_played '
            . 'ON lastfm_import_buffer (lastfm_user, played_at)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE lastfm_import_buffer');
    }
}
