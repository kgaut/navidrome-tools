<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Issue #319: cache of the ListenBrainz metadata lookups used by
 * `app:loves:push` for tracks without MBID — found or not found — so they are
 * not searched again every night.
 */
final class Version20261128100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add listenbrainz_lookup_cache (#319).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE listenbrainz_lookup_cache (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, lookup_key VARCHAR(512) NOT NULL, recording_mbid VARCHAR(64) DEFAULT NULL, checked_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX uniq_lb_lookup_key ON listenbrainz_lookup_cache (lookup_key)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE listenbrainz_lookup_cache');
    }
}
