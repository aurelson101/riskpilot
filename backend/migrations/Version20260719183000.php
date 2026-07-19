<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260719183000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Stocke les données propres aux méthodes ISO 27005 et EBIOS RM';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE risk_scenarios ADD method_data JSON DEFAULT '{}' NOT NULL");
        $this->addSql('ALTER TABLE risk_scenarios ALTER method_data DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE risk_scenarios DROP method_data');
    }
}
