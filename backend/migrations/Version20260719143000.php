<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260719143000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute un abonnement iCalendar privé et révocable par utilisateur';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD calendar_token_hash VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD calendar_token_created_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_calendar_token ON users (calendar_token_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_user_calendar_token');
        $this->addSql('ALTER TABLE users DROP calendar_token_hash');
        $this->addSql('ALTER TABLE users DROP calendar_token_created_at');
    }
}
