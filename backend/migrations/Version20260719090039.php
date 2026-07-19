<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260719090039 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create tenant-validated relations between assets.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE asset_relations (asset_source INT NOT NULL, asset_target INT NOT NULL, PRIMARY KEY (asset_source, asset_target))');
        $this->addSql('CREATE INDEX IDX_F9565742B7A7F06E ON asset_relations (asset_source)');
        $this->addSql('CREATE INDEX IDX_F9565742AE42A0E1 ON asset_relations (asset_target)');
        $this->addSql('ALTER TABLE asset_relations ADD CONSTRAINT FK_F9565742B7A7F06E FOREIGN KEY (asset_source) REFERENCES assets (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE asset_relations ADD CONSTRAINT FK_F9565742AE42A0E1 FOREIGN KEY (asset_target) REFERENCES assets (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE asset_relations DROP CONSTRAINT FK_F9565742B7A7F06E');
        $this->addSql('ALTER TABLE asset_relations DROP CONSTRAINT FK_F9565742AE42A0E1');
        $this->addSql('DROP TABLE asset_relations');
    }
}
