<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260629120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add stats_history table: one daily measurement of the library size for the evolution chart.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE stats_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                day VARCHAR(10) NOT NULL UNIQUE,
                tracks INTEGER NOT NULL,
                artists INTEGER NOT NULL,
                albums INTEGER NOT NULL,
                duration_seconds INTEGER NOT NULL,
                computed_at DATETIME NOT NULL
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE stats_history');
    }
}
