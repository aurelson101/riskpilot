<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260719123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les métadonnées des fichiers Word aux documents ISMS.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE isms_documents ADD file_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE isms_documents ADD file_storage_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE isms_documents ADD file_mime_type VARCHAR(150) DEFAULT NULL');
        $this->addSql('ALTER TABLE isms_documents ADD file_size INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE isms_documents DROP file_name');
        $this->addSql('ALTER TABLE isms_documents DROP file_storage_name');
        $this->addSql('ALTER TABLE isms_documents DROP file_mime_type');
        $this->addSql('ALTER TABLE isms_documents DROP file_size');
    }
}
