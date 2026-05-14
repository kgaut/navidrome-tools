<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260514100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add strawberry_attempted_at to lastfm_import_buffer: distinguishes "never attempted for Strawberry" (NULL) from "attempted but unmatched" (timestamp set, synced_strawberry still 0).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE lastfm_import_buffer ADD COLUMN strawberry_attempted_at DATETIME DEFAULT NULL');
        $this->addSql(
            'CREATE INDEX idx_lastfm_buffer_sb_unmatched '
            . 'ON lastfm_import_buffer (synced_strawberry, strawberry_attempted_at)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_lastfm_buffer_sb_unmatched');
        // SQLite does not support DROP COLUMN before 3.35 — skip for down migration.
        // Run Version20260514000000 down to fully rollback.
    }
}
