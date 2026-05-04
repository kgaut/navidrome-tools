<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260504300000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add top_snapshot table caching top artists/albums/tracks per (window_from, window_to, client).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE top_snapshot (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                window_from INTEGER NOT NULL,
                window_to INTEGER NOT NULL,
                client VARCHAR(64) DEFAULT NULL,
                data CLOB NOT NULL,
                computed_at DATETIME NOT NULL
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX top_snapshot_window_uniq ON top_snapshot (window_from, window_to, client)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE top_snapshot');
    }
}
