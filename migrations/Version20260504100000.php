<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260504100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop playlist_definition.schedule (no more internal cron — runs are launched from the host crontab).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE playlist_definition DROP COLUMN schedule');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE playlist_definition ADD COLUMN schedule VARCHAR(100) DEFAULT NULL');
    }
}
