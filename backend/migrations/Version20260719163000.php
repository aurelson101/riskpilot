<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260719163000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le chaînage cryptographique et la corrélation du journal audit';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_logs ADD previous_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE audit_logs ADD event_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE audit_logs ADD request_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_audit_event_hash ON audit_logs (event_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_audit_event_hash');
        $this->addSql('ALTER TABLE audit_logs DROP previous_hash');
        $this->addSql('ALTER TABLE audit_logs DROP event_hash');
        $this->addSql('ALTER TABLE audit_logs DROP request_id');
    }
}
