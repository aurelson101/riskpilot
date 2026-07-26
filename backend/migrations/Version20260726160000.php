<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260726160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the FR or EN interface preference to user profiles';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD locale VARCHAR(2) DEFAULT NULL');
        $this->addSql("UPDATE users SET locale = 'fr'");
        $this->addSql('ALTER TABLE users ALTER locale SET NOT NULL');
        $this->addSql("ALTER TABLE users ADD CONSTRAINT chk_user_locale CHECK (locale IN ('fr', 'en'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP CONSTRAINT chk_user_locale');
        $this->addSql('ALTER TABLE users DROP locale');
    }
}
