<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260719121500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute OAuth 2.0 Google Workspace et Microsoft 365';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email_settings ADD oauth_client_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE email_settings ADD encrypted_oauth_client_secret TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE email_settings ADD encrypted_access_token TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE email_settings ADD encrypted_refresh_token TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE email_settings ADD access_token_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE email_settings ADD connected_email VARCHAR(180) DEFAULT NULL');
        $this->addSql('ALTER TABLE email_settings ADD oauth_tenant VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE email_settings ADD oauth_state_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE email_settings ADD oauth_state_expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        foreach (['oauth_client_id', 'encrypted_oauth_client_secret', 'encrypted_access_token', 'encrypted_refresh_token', 'access_token_expires_at', 'connected_email', 'oauth_tenant', 'oauth_state_hash', 'oauth_state_expires_at'] as $column) {
            $this->addSql('ALTER TABLE email_settings DROP '.$column);
        }
    }
}
