<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260719170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Conserve les fichiers binaires et métadonnées de chaque version documentaire';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE isms_document_versions ADD file_storage_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE isms_document_versions ADD file_mime_type VARCHAR(150) DEFAULT NULL');
        $this->addSql('ALTER TABLE isms_document_versions ADD file_size INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE isms_document_versions DROP file_storage_name');
        $this->addSql('ALTER TABLE isms_document_versions DROP file_mime_type');
        $this->addSql('ALTER TABLE isms_document_versions DROP file_size');
    }
}
