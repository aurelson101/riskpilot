<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ActionPlan;
use App\Entity\Asset;
use App\Entity\Organization;
use App\Entity\RiskScenario;
use App\Entity\Scope;
use App\Entity\Threat;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class ActionPlanTest extends TestCase
{
    public function testOverdueStatusIsComputedFromDueDate(): void
    {
        $organization = new Organization('Test');
        $owner = new User('owner@example.test', 'Action', 'Owner', $organization);
        $scope = new Scope('Scope', 'PROJECT', $organization);
        $asset = new Asset('Asset', 'APPLICATION', $scope, $organization);
        $risk = new RiskScenario('Risk', $organization, $scope, $asset, new Threat('Threat', 'HUMAN', $organization), $owner);
        $action = new ActionPlan('Late action', $organization, $risk, $owner, new \DateTimeImmutable('yesterday'));

        self::assertSame('OVERDUE', $action->getStatus());
        $action->setStatus('COMPLETED');
        self::assertSame('COMPLETED', $action->getStatus());
        self::assertSame(100, $action->getProgress());
        self::assertNotNull($action->getCompletionDate());
    }
}
