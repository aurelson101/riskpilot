<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260719182000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la charge estimée aux traitements de risques';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE action_plans ADD estimated_effort_days NUMERIC(8, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE action_plans DROP estimated_effort_days');
    }
}
