<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AssistantProposal;
use App\Entity\Organization;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class AssistantProposalTest extends TestCase
{
    public function testValidationMustBeIndependentAndTenantScoped(): void
    {
        $organization = new Organization('Tenant A');
        $requester = new User('requester@example.test', 'Proposal', 'Requester', $organization);
        $validator = new User('validator@example.test', 'Proposal', 'Validator', $organization);
        $proposal = new AssistantProposal(
            $organization,
            $requester,
            'GAP_SUMMARY',
            [],
            ['summary' => 'Controlled proposal'],
            [['type' => 'CONTROL', 'id' => 1, 'label' => 'Access review']],
        );

        try {
            $proposal->validate($requester, 'APPROVED', 'Self approval');
            self::fail('Self approval should be rejected.');
        } catch (\LogicException) {
            self::assertSame('PENDING', $proposal->getStatus());
        }
        $proposal->validate($validator, 'APPROVED', 'Sources checked');
        self::assertSame('APPROVED', $proposal->getStatus());
    }
}
