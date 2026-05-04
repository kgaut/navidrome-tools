<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drop the Symfony Messenger worker infrastructure: the run_history.progress
 * JSON column (used to render the live progress bar in the history detail
 * page) and the messenger_messages queue table (auto-created on first
 * dispatch by the Doctrine transport).
 *
 * Last.fm long-runners are now CLI-only and synchronous, so the
 * queue + the live progress polling have no consumer left.
 */
final class Version20260504212838 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop run_history.progress column and messenger_messages table (worker removed).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE run_history DROP COLUMN progress');
        $this->addSql('DROP TABLE IF EXISTS messenger_messages');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE run_history ADD COLUMN progress CLOB DEFAULT NULL');
        // messenger_messages is recreated automatically by the Doctrine
        // transport when auto_setup=true (was the case until this
        // migration). No manual recreation needed.
    }
}
