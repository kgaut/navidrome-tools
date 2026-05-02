<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260502000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add lastfm_alias table for manual Last.fm → media_file mappings.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE lastfm_alias (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                source_artist VARCHAR(255) NOT NULL,
                source_title VARCHAR(255) NOT NULL,
                source_artist_norm VARCHAR(255) NOT NULL,
                source_title_norm VARCHAR(255) NOT NULL,
                target_media_file_id VARCHAR(255) DEFAULT NULL,
                created_at DATETIME NOT NULL
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_lastfm_alias_source_norm ON lastfm_alias (source_artist_norm, source_title_norm)');
        $this->addSql('CREATE INDEX idx_lastfm_alias_source_norm ON lastfm_alias (source_artist_norm, source_title_norm)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE lastfm_alias');
    }
}
