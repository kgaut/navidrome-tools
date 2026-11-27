<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Issue #310: listens can now come from ListenBrainz as well as Last.fm. Each
 * row records the service it was fetched from; every existing row came from
 * Last.fm, hence the default.
 */
final class Version20261127120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add scrobbles.source (lastfm|listenbrainz) — existing rows are Last.fm (#310).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE scrobbles ADD COLUMN source VARCHAR(32) DEFAULT 'lastfm' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE scrobbles DROP COLUMN source');
    }
}
