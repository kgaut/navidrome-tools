<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260514220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add scrobble_sync table: tracks sync status per scrobble per target (navidrome, strawberry).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE scrobble_sync (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                scrobble_id INTEGER NOT NULL REFERENCES scrobbles(id) ON DELETE CASCADE,
                target VARCHAR(32) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'pending',
                target_id VARCHAR(255) DEFAULT NULL,
                strategy VARCHAR(32) DEFAULT NULL,
                attempted_at DATETIME DEFAULT NULL,
                synced_at DATETIME DEFAULT NULL,
                run_id INTEGER DEFAULT NULL REFERENCES run_history(id) ON DELETE SET NULL
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_scrobble_sync_scrobble_target ON scrobble_sync (scrobble_id, target)');
        $this->addSql('CREATE INDEX idx_scrobble_sync_target_status ON scrobble_sync (target, status)');
        $this->addSql('CREATE INDEX idx_scrobble_sync_run ON scrobble_sync (run_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE scrobble_sync');
    }
}
