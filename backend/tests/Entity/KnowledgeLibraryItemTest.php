<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\KnowledgeLibraryItem;
use App\Entity\Organization;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class KnowledgeLibraryItemTest extends TestCase
{
    public function testApprovalRequiresIndependentReviewerAndRevisionIsImmutable(): void
    {
        $organization = new Organization('Primary');
        $owner = new User('owner@example.test', 'Item', 'Owner', $organization);
        $approver = new User('approver@example.test', 'Item', 'Approver', $organization);
        $first = new KnowledgeLibraryItem($organization, $owner, 'risk.ransomware', 'RISK_SCENARIO', 'Ransomware', 1, ['impact' => 'HIGH']);
        $first->submit();
        $first->approve($approver);
        $second = new KnowledgeLibraryItem($organization, $owner, 'risk.ransomware', 'RISK_SCENARIO', 'Ransomware updated', 2, ['impact' => 'CRITICAL'], [], null, null, $first);

        self::assertSame('APPROVED', $first->getStatus());
        self::assertSame('HIGH', $first->getContent()['impact']);
        self::assertSame($first, $second->getSupersedes());
        self::assertSame(2, $second->getVersion());
    }

    public function testOwnerCannotApproveOwnItem(): void
    {
        $organization = new Organization('Primary');
        $owner = new User('owner@example.test', 'Item', 'Owner', $organization);
        $item = new KnowledgeLibraryItem($organization, $owner, 'control.access', 'CONTROL', 'Access', 1, ['objective' => 'Protect']);
        $item->submit();

        $this->expectException(\LogicException::class);
        $item->approve($owner);
    }
}
