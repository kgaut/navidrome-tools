<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261008080000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add audio_feature table: tempo, key, energy, moods and genres imported from AudioMuse-AI (#257).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE audio_feature (
                media_file_id VARCHAR(64) NOT NULL PRIMARY KEY,
                tempo DOUBLE PRECISION DEFAULT NULL,
                music_key VARCHAR(8) DEFAULT NULL,
                scale VARCHAR(8) DEFAULT NULL,
                energy DOUBLE PRECISION DEFAULT NULL,
                danceable DOUBLE PRECISION DEFAULT NULL,
                aggressive DOUBLE PRECISION DEFAULT NULL,
                happy DOUBLE PRECISION DEFAULT NULL,
                party DOUBLE PRECISION DEFAULT NULL,
                relaxed DOUBLE PRECISION DEFAULT NULL,
                sad DOUBLE PRECISION DEFAULT NULL,
                top_genre VARCHAR(64) DEFAULT NULL,
                genres CLOB NOT NULL,
                fingerprint VARCHAR(32) NOT NULL,
                synced_at DATETIME NOT NULL
            )
        SQL);
        $this->addSql('CREATE INDEX idx_audio_feature_tempo ON audio_feature (tempo)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE audio_feature');
    }
}
