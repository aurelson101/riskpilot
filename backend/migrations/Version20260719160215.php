<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260719160215 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE third_parties ADD certifications JSON NOT NULL');
        $this->addSql('ALTER TABLE third_parties ADD risk_summary TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE third_parties ADD compensating_measures TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE third_parties DROP certifications');
        $this->addSql('ALTER TABLE third_parties DROP risk_summary');
        $this->addSql('ALTER TABLE third_parties DROP compensating_measures');
    }
}
