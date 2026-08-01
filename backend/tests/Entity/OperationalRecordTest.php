<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\OperationalRecord;
use App\Entity\Organization;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class OperationalRecordTest extends TestCase
{
    public function testRecordRejectsCrossTenantOwner(): void
    {
        $organization = new Organization('Primary');
        $other = new Organization('Other');
        $record = new OperationalRecord($organization, 'TASK', 'Collect evidence');

        $this->expectException(\InvalidArgumentException::class);
        $record->update('Collect evidence', 'ACTIVE', [], new User('other@example.test', 'Other', 'Owner', $other), new \DateTimeImmutable('+1 day'));
    }

    public function testRecordStoresGovernedConfiguration(): void
    {
        $organization = new Organization('Primary');
        $owner = new User('owner@example.test', 'Task', 'Owner', $organization);
        $record = new OperationalRecord($organization, 'COMPLIANCE_PROGRAM', 'ISO 27001 target');
        $dueAt = new \DateTimeImmutable('2026-12-31');

        $record->update('ISO 27001 target', 'ACTIVE', ['currentScore' => 25, 'targetScore' => 100], $owner, $dueAt);

        self::assertSame('COMPLIANCE_PROGRAM', $record->getType());
        self::assertSame(25, $record->getDetails()['currentScore']);
        self::assertSame($owner, $record->getOwner());
        self::assertSame($dueAt, $record->getDueAt());
    }
}
