<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Issue #313: the Strawberry integration is removed. Its sync state
 * (`scrobble_sync` rows of target `strawberry`) and any Strawberry job still
 * queued (its class no longer exists, the worker could not decode it) go with
 * it. Irreversible: the state of a removed integration is not restored.
 */
final class Version20261127160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop Strawberry sync state and queued Strawberry jobs (#313).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DELETE FROM scrobble_sync WHERE target = 'strawberry'");
        if ($schema->hasTable('messenger_messages')) {
            $this->addSql("DELETE FROM messenger_messages WHERE body LIKE '%SyncStrawberryMessage%'");
        }
    }

    public function down(Schema $schema): void
    {
        // Sync state of a removed integration: nothing to restore.
    }
}
