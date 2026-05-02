<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260502100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add lastfm_artist_alias table for artist-level renames / synonyms (e.g. "La Ruda Salska" → "La Ruda").';
    }

    public function isTransactional(): bool
    {
        // SQLite + DDL combined with `--all-or-nothing` triggers
        // « There is no active transaction » on the migration runner.
        // Each CREATE TABLE / CREATE INDEX is auto-committed by SQLite
        // anyway, so wrapping is moot.
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE lastfm_artist_alias (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                source_artist VARCHAR(255) NOT NULL,
                source_artist_norm VARCHAR(255) NOT NULL,
                target_artist VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_lastfm_artist_alias_source_norm ON lastfm_artist_alias (source_artist_norm)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE lastfm_artist_alias');
    }
}
