<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260719174000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rend le journal d’audit append-only au niveau PostgreSQL';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE FUNCTION riskpilot_audit_append_only() RETURNS trigger AS $func$ BEGIN RAISE EXCEPTION \'audit_logs is append-only\'; END; $func$ LANGUAGE plpgsql');
        $this->addSql('CREATE TRIGGER audit_logs_append_only BEFORE UPDATE OR DELETE ON audit_logs FOR EACH ROW EXECUTE FUNCTION riskpilot_audit_append_only()');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TRIGGER audit_logs_append_only ON audit_logs');
        $this->addSql('DROP FUNCTION riskpilot_audit_append_only()');
    }
}
