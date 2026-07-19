<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260719124500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute le circuit d’approbation et les empreintes de versions aux documents ISMS.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE isms_documents ADD approved_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE isms_documents ADD file_checksum VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE isms_documents ADD approved_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE isms_documents ADD next_review_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE isms_documents ADD CONSTRAINT FK_ISMS_DOCUMENT_APPROVER FOREIGN KEY (approved_by_id) REFERENCES users (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_2995FB132D234F6A ON isms_documents (approved_by_id)');
        $this->addSql('ALTER TABLE isms_document_versions ADD file_name VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE isms_document_versions ADD file_checksum VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE isms_documents DROP CONSTRAINT FK_ISMS_DOCUMENT_APPROVER');
        $this->addSql('DROP INDEX IDX_2995FB132D234F6A');
        $this->addSql('ALTER TABLE isms_documents DROP approved_by_id');
        $this->addSql('ALTER TABLE isms_documents DROP file_checksum');
        $this->addSql('ALTER TABLE isms_documents DROP approved_at');
        $this->addSql('ALTER TABLE isms_documents DROP next_review_at');
        $this->addSql('ALTER TABLE isms_document_versions DROP file_name');
        $this->addSql('ALTER TABLE isms_document_versions DROP file_checksum');
    }
}
