<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260514210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add scrobbles table: local source of truth for Last.fm scrobbles.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE scrobbles (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                lastfm_user VARCHAR(255) NOT NULL,
                artist VARCHAR(255) NOT NULL,
                title VARCHAR(255) NOT NULL,
                album VARCHAR(255) DEFAULT NULL,
                album_artist VARCHAR(255) DEFAULT NULL,
                mbid_track VARCHAR(64) DEFAULT NULL,
                mbid_artist VARCHAR(64) DEFAULT NULL,
                mbid_album VARCHAR(64) DEFAULT NULL,
                played_at DATETIME NOT NULL,
                loved BOOLEAN NOT NULL DEFAULT 0,
                image_url CLOB DEFAULT NULL,
                fetched_at DATETIME NOT NULL
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_scrobble_user_played_track ON scrobbles (lastfm_user, played_at, artist, title)');
        $this->addSql('CREATE INDEX idx_scrobble_user_played ON scrobbles (lastfm_user, played_at)');
        $this->addSql('CREATE INDEX idx_scrobble_played_at ON scrobbles (played_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE scrobbles');
    }
}
