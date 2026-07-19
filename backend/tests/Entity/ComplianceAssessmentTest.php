<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ComplianceAssessment;
use App\Entity\ComplianceResult;
use App\Entity\Framework;
use App\Entity\Organization;
use App\Entity\Requirement;
use App\Entity\Scope;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class ComplianceAssessmentTest extends TestCase
{
    public function testScoreExcludesNotApplicableAndNotAssessedResults(): void
    {
        $organization = new Organization('Test');
        $framework = new Framework('Public', '1');
        $scope = new Scope('Scope', 'PROJECT', $organization);
        $user = new User('auditor@example.test', 'Audit', 'User', $organization);
        $assessment = new ComplianceAssessment($organization, $framework, $scope, $user, new \DateTimeImmutable());
        (new ComplianceResult($assessment, new Requirement($framework, 'A', 'A', 'Domain')))->setComplianceStatus('COMPLIANT');
        (new ComplianceResult($assessment, new Requirement($framework, 'B', 'B', 'Domain')))->setComplianceStatus('PARTIAL');
        new ComplianceResult($assessment, new Requirement($framework, 'C', 'C', 'Domain'));
        (new ComplianceResult($assessment, new Requirement($framework, 'D', 'D', 'Domain')))->setComplianceStatus('NOT_APPLICABLE');

        $assessment->recalculateScore();
        self::assertSame(75.0, $assessment->getGlobalScore());
    }
}
