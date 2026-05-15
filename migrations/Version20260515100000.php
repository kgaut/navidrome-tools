<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add messenger_messages table for Symfony Messenger doctrine transport.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE messenger_messages (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                body CLOB NOT NULL,
                headers CLOB NOT NULL,
                queue_name VARCHAR(190) NOT NULL,
                created_at DATETIME NOT NULL,
                available_at DATETIME NOT NULL,
                delivered_at DATETIME DEFAULT NULL
            )
        SQL);
        $this->addSql('CREATE INDEX idx_messenger_messages_queue_name ON messenger_messages (queue_name)');
        $this->addSql('CREATE INDEX idx_messenger_messages_available_at ON messenger_messages (available_at)');
        $this->addSql('CREATE INDEX idx_messenger_messages_delivered_at ON messenger_messages (delivered_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE messenger_messages');
    }
}
