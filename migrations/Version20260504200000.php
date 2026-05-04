<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260504200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add run_history.progress JSON column for live UI progress tracking on async jobs.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE run_history ADD COLUMN progress CLOB DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE run_history DROP COLUMN progress');
    }
}
