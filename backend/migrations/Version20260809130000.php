<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260809130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Preserve the immutable actor identifier in audit events';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_logs ADD actor_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE audit_logs ADD hash_version INT DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE audit_logs DISABLE TRIGGER audit_logs_append_only');
        $this->addSql('UPDATE audit_logs SET actor_id = user_id WHERE user_id IS NOT NULL');
        $this->addSql('ALTER TABLE audit_logs ENABLE TRIGGER audit_logs_append_only');
        $this->addSql('ALTER TABLE audit_logs ALTER hash_version DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_logs DROP actor_id');
        $this->addSql('ALTER TABLE audit_logs DROP hash_version');
    }
}
