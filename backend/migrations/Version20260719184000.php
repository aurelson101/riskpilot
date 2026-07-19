<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260719184000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Trace les relances quotidiennes des revues de risques';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE risk_reviews ADD last_reminder_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE risk_reviews DROP last_reminder_at');
    }
}
