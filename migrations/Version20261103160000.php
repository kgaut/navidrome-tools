<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Issue #284: positive « couple » cache entries were resolved by the old
 * tie-break (lowest id), which often picked an edition (« … -
 * Instrumentales »). They keep sending album-less scrobbles there. Dropped so
 * the next match recomputes them with the new tie-break — cheap since the
 * couple step is served from the in-memory library index (#282). Negatives,
 * aliases and other strategies are kept. Irreversible by nature (a cache).
 */
final class Version20261103160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop positive couple-strategy match cache entries resolved with the old tie-break (#284).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("DELETE FROM lastfm_match_cache WHERE target_media_file_id IS NOT NULL AND strategy = 'couple'");
    }

    public function down(Schema $schema): void
    {
        // Cache rows: nothing to restore, they are recomputed on demand.
    }
}
