<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260801194500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align P1 and P3 relation index names with Doctrine metadata';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER INDEX idx_operational_organization RENAME TO "IDX_347F3B2532C8A3DE"');
        $this->addSql('ALTER INDEX idx_operational_owner RENAME TO "IDX_347F3B257E3C61F9"');
        $this->addSql('ALTER INDEX idx_assistant_requested_by RENAME TO "IDX_252F6E5C4DA1E751"');
        $this->addSql('ALTER INDEX idx_assistant_validated_by RENAME TO "IDX_252F6E5CC69DE5E5"');
        $this->addSql('ALTER INDEX idx_library_owner RENAME TO "IDX_1AC243E37E3C61F9"');
        $this->addSql('ALTER INDEX idx_library_supersedes RENAME TO "IDX_1AC243E37A7685A8"');
        $this->addSql('ALTER INDEX idx_library_approved_by RENAME TO "IDX_1AC243E32D234F6A"');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER INDEX "IDX_347F3B2532C8A3DE" RENAME TO idx_operational_organization');
        $this->addSql('ALTER INDEX "IDX_347F3B257E3C61F9" RENAME TO idx_operational_owner');
        $this->addSql('ALTER INDEX "IDX_252F6E5C4DA1E751" RENAME TO idx_assistant_requested_by');
        $this->addSql('ALTER INDEX "IDX_252F6E5CC69DE5E5" RENAME TO idx_assistant_validated_by');
        $this->addSql('ALTER INDEX "IDX_1AC243E37E3C61F9" RENAME TO idx_library_owner');
        $this->addSql('ALTER INDEX "IDX_1AC243E37A7685A8" RENAME TO idx_library_supersedes');
        $this->addSql('ALTER INDEX "IDX_1AC243E32D234F6A" RENAME TO idx_library_approved_by');
    }
}
