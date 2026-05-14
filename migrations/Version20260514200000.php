<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260514200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema: run_history, setting, lastfm_alias, lastfm_artist_alias, lastfm_match_cache.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE run_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                type VARCHAR(32) NOT NULL,
                reference VARCHAR(255) NOT NULL,
                label VARCHAR(255) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'success',
                started_at DATETIME NOT NULL,
                finished_at DATETIME DEFAULT NULL,
                duration_ms INTEGER DEFAULT NULL,
                message CLOB DEFAULT NULL,
                metrics CLOB DEFAULT NULL
            )
        SQL);
        $this->addSql('CREATE INDEX idx_run_history_type ON run_history (type)');
        $this->addSql('CREATE INDEX idx_run_history_status ON run_history (status)');
        $this->addSql('CREATE INDEX idx_run_history_started_at ON run_history (started_at)');

        $this->addSql(<<<'SQL'
            CREATE TABLE setting (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                "key" VARCHAR(64) NOT NULL,
                value CLOB NOT NULL DEFAULT '',
                UNIQUE ("key")
            )
        SQL);

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

        $this->addSql(<<<'SQL'
            CREATE TABLE lastfm_match_cache (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                source_artist VARCHAR(255) NOT NULL,
                source_title VARCHAR(255) NOT NULL,
                source_artist_norm VARCHAR(255) NOT NULL,
                source_title_norm VARCHAR(255) NOT NULL,
                target_media_file_id VARCHAR(255) DEFAULT NULL,
                strategy VARCHAR(32) NOT NULL,
                confidence_score INTEGER DEFAULT NULL,
                resolved_at DATETIME NOT NULL
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_lastfm_match_cache_source_norm ON lastfm_match_cache (source_artist_norm, source_title_norm)');
        $this->addSql('CREATE INDEX idx_lastfm_match_cache_artist_norm ON lastfm_match_cache (source_artist_norm)');
        $this->addSql('CREATE INDEX idx_lastfm_match_cache_resolved_at ON lastfm_match_cache (resolved_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE lastfm_match_cache');
        $this->addSql('DROP TABLE lastfm_artist_alias');
        $this->addSql('DROP TABLE lastfm_alias');
        $this->addSql('DROP TABLE setting');
        $this->addSql('DROP TABLE run_history');
    }
}
