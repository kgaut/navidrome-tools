<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261007220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add described_playlist table: playlists described in free text, resolved by AudioMuse CLAP search (#258).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE described_playlist (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                name VARCHAR(100) NOT NULL,
                query VARCHAR(300) NOT NULL,
                track_count INTEGER NOT NULL,
                max_plays INTEGER DEFAULT NULL,
                created_at DATETIME NOT NULL
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_described_playlist_name ON described_playlist (name)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE described_playlist');
    }
}
