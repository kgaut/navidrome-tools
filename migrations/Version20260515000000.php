<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add stats_snapshot table: cached listening statistics per period.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE stats_snapshot (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                period VARCHAR(32) NOT NULL UNIQUE,
                data CLOB NOT NULL,
                computed_at DATETIME NOT NULL
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE stats_snapshot');
    }
}
