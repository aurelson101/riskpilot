<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Asset;
use App\Entity\Organization;
use App\Entity\RiskAcceptance;
use App\Entity\RiskGovernancePolicy;
use App\Entity\RiskScenario;
use App\Entity\Scope;
use App\Entity\Threat;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class RiskGovernanceTest extends TestCase
{
    public function testPolicyPositionsAndAcceptanceExpiry(): void
    {
        $organization = new Organization('Test');
        $owner = new User('owner@example.test', 'Olivia', 'Owner', $organization);
        $decider = new User('decider@example.test', 'Diane', 'Direction', $organization);
        $scope = new Scope('SI', 'DEPARTMENT', $organization);
        $asset = new Asset('ERP', 'APPLICATION', $scope, $organization);
        $threat = new Threat('Panne', 'TECHNICAL', $organization);
        $risk = new RiskScenario('Panne ERP', $organization, $scope, $asset, $threat, $owner);
        $policy = new RiskGovernancePolicy($organization, 'SI', 'CYBER', $owner);
        $policy->update('SI', 'CYBER', 4, 9, 16, 'ISO_27005', null, $owner);

        self::assertSame('WITHIN_APPETITE', $policy->position(4));
        self::assertSame('TOLERATED', $policy->position(8));
        self::assertSame('ABOVE_TOLERANCE', $policy->position(12));
        self::assertSame('ABOVE_CAPACITY', $policy->position(20));

        $acceptance = new RiskAcceptance($risk, $owner, 'Décision de test', 'Direction', new \DateTimeImmutable('-1 day'), null);
        $acceptance->decide('APPROVED', $decider, null);
        self::assertSame('EXPIRED', $acceptance->getStatus());
        $acceptance->expire();
        self::assertSame('EXPIRED', $acceptance->getStoredStatus());
        self::assertSame('IN_REVIEW', $risk->getStatus());
    }

    public function testPolicyRejectsIncoherentThresholds(): void
    {
        $organization = new Organization('Test');
        $owner = new User('owner@example.test', 'Olivia', 'Owner', $organization);
        $policy = new RiskGovernancePolicy($organization, 'SI', 'CYBER', $owner);

        $this->expectException(\InvalidArgumentException::class);
        $policy->update('SI', 'CYBER', 12, 8, 16, 'SIMPLIFIED', null, $owner);
    }
}
