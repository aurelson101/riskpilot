<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121090149 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Conditions contractuelles DORA et lien entre signalements CRA et vulnérabilités.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ict_arrangements ADD governing_law VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE ict_arrangements ADD termination_notice_days SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE ict_arrangements ADD last_audited_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE regulatory_incidents ADD vulnerability_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE regulatory_incidents ADD CONSTRAINT FK_739F3DA372897D8B FOREIGN KEY (vulnerability_id) REFERENCES vulnerabilities (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_739F3DA372897D8B ON regulatory_incidents (vulnerability_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ict_arrangements DROP governing_law');
        $this->addSql('ALTER TABLE ict_arrangements DROP termination_notice_days');
        $this->addSql('ALTER TABLE ict_arrangements DROP last_audited_at');
        $this->addSql('ALTER TABLE regulatory_incidents DROP CONSTRAINT FK_739F3DA372897D8B');
        $this->addSql('DROP INDEX IDX_739F3DA372897D8B');
        $this->addSql('ALTER TABLE regulatory_incidents DROP vulnerability_id');
    }
}
