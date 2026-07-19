<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260719181000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Aligne les index et valeurs par défaut du modèle de gouvernance des risques';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER INDEX idx_risk_policy_org RENAME TO IDX_9AFA87DD32C8A3DE');
        $this->addSql('ALTER INDEX idx_risk_policy_owner RENAME TO IDX_9AFA87DD7E3C61F9');
        $this->addSql('ALTER INDEX idx_risk_review_risk RENAME TO IDX_236F8540235B6D1');
        $this->addSql('ALTER INDEX idx_risk_review_reviewer RENAME TO IDX_236F854070574616');
        $this->addSql('ALTER INDEX idx_risk_campaign_org RENAME TO IDX_8F9D540332C8A3DE');
        $this->addSql('ALTER INDEX idx_risk_campaign_coordinator RENAME TO IDX_8F9D5403E7877946');
        $this->addSql('ALTER INDEX idx_risk_acceptance_org RENAME TO IDX_7FE2D7A432C8A3DE');
        $this->addSql('ALTER INDEX idx_risk_acceptance_risk RENAME TO IDX_7FE2D7A4235B6D1');
        $this->addSql('ALTER INDEX idx_risk_acceptance_requester RENAME TO IDX_7FE2D7A44DA1E751');
        $this->addSql('ALTER INDEX idx_risk_acceptance_decider RENAME TO IDX_7FE2D7A4E26B496B');
        $this->addSql('ALTER TABLE risk_scenarios ALTER family DROP DEFAULT');
        $this->addSql('ALTER TABLE risk_scenarios ALTER analysis_method DROP DEFAULT');
        $this->addSql('ALTER TABLE risk_scenarios ALTER strategic DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE risk_scenarios ALTER family SET DEFAULT 'GENERAL'");
        $this->addSql("ALTER TABLE risk_scenarios ALTER analysis_method SET DEFAULT 'SIMPLIFIED'");
        $this->addSql('ALTER TABLE risk_scenarios ALTER strategic SET DEFAULT FALSE');
        $this->addSql('ALTER INDEX IDX_9AFA87DD32C8A3DE RENAME TO idx_risk_policy_org');
        $this->addSql('ALTER INDEX IDX_9AFA87DD7E3C61F9 RENAME TO idx_risk_policy_owner');
        $this->addSql('ALTER INDEX IDX_236F8540235B6D1 RENAME TO idx_risk_review_risk');
        $this->addSql('ALTER INDEX IDX_236F854070574616 RENAME TO idx_risk_review_reviewer');
        $this->addSql('ALTER INDEX IDX_8F9D540332C8A3DE RENAME TO idx_risk_campaign_org');
        $this->addSql('ALTER INDEX IDX_8F9D5403E7877946 RENAME TO idx_risk_campaign_coordinator');
        $this->addSql('ALTER INDEX IDX_7FE2D7A432C8A3DE RENAME TO idx_risk_acceptance_org');
        $this->addSql('ALTER INDEX IDX_7FE2D7A4235B6D1 RENAME TO idx_risk_acceptance_risk');
        $this->addSql('ALTER INDEX IDX_7FE2D7A44DA1E751 RENAME TO idx_risk_acceptance_requester');
        $this->addSql('ALTER INDEX IDX_7FE2D7A4E26B496B RENAME TO idx_risk_acceptance_decider');
    }
}
