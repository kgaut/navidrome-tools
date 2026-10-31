<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261031180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add OAuth 2.1 tables (league/oauth2-server-bundle) for the MCP server (#276).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE oauth2_client (
                identifier VARCHAR(32) NOT NULL PRIMARY KEY,
                name VARCHAR(128) NOT NULL,
                secret VARCHAR(128) DEFAULT NULL,
                redirect_uris CLOB DEFAULT NULL,
                grants CLOB DEFAULT NULL,
                scopes CLOB DEFAULT NULL,
                active BOOLEAN NOT NULL,
                allow_plain_text_pkce BOOLEAN DEFAULT 0 NOT NULL
            )
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE oauth2_access_token (
                identifier CHAR(80) NOT NULL PRIMARY KEY,
                expiry DATETIME NOT NULL,
                user_identifier VARCHAR(128) DEFAULT NULL,
                scopes CLOB DEFAULT NULL,
                revoked BOOLEAN NOT NULL,
                client VARCHAR(32) NOT NULL,
                CONSTRAINT FK_454D9673C7440455 FOREIGN KEY (client) REFERENCES oauth2_client (identifier) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_454D9673C7440455 ON oauth2_access_token (client)');
        $this->addSql(<<<'SQL'
            CREATE TABLE oauth2_authorization_code (
                identifier CHAR(80) NOT NULL PRIMARY KEY,
                expiry DATETIME NOT NULL,
                user_identifier VARCHAR(128) DEFAULT NULL,
                scopes CLOB DEFAULT NULL,
                revoked BOOLEAN NOT NULL,
                client VARCHAR(32) NOT NULL,
                CONSTRAINT FK_509FEF5FC7440455 FOREIGN KEY (client) REFERENCES oauth2_client (identifier) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_509FEF5FC7440455 ON oauth2_authorization_code (client)');
        $this->addSql(<<<'SQL'
            CREATE TABLE oauth2_refresh_token (
                identifier CHAR(80) NOT NULL PRIMARY KEY,
                expiry DATETIME NOT NULL,
                revoked BOOLEAN NOT NULL,
                access_token CHAR(80) DEFAULT NULL,
                CONSTRAINT FK_4DD90732B6A2DD68 FOREIGN KEY (access_token) REFERENCES oauth2_access_token (identifier) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE
            )
        SQL);
        $this->addSql('CREATE INDEX IDX_4DD90732B6A2DD68 ON oauth2_refresh_token (access_token)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE oauth2_refresh_token');
        $this->addSql('DROP TABLE oauth2_authorization_code');
        $this->addSql('DROP TABLE oauth2_access_token');
        $this->addSql('DROP TABLE oauth2_client');
    }
}
