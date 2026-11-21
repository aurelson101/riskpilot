<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261121081147 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rattachement SCIM : identifiant externe du compte chez le fournisseur d’identité.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE users ADD scim_external_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_user_scim_external_id ON users (organization_id, scim_external_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX uniq_user_scim_external_id');
        $this->addSql('ALTER TABLE users DROP scim_external_id');
    }
}
