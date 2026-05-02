<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260502200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add lastfm_match_cache table to memoize positive and negative scrobble→media_file resolutions across imports.';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
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
    }
}
