<?php

declare(strict_types=1);

namespace App\Tests\Domain\Compliance;

use App\Domain\Compliance\TisaxAssessmentSummary;
use App\Entity\ComplianceAssessment;
use App\Entity\ComplianceResult;
use App\Entity\Framework;
use App\Entity\Organization;
use App\Entity\Requirement;
use App\Entity\Scope;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class TisaxAssessmentSummaryTest extends TestCase
{
    /** @return array{ComplianceAssessment, array<string, ComplianceResult>} */
    private function fixture(): array
    {
        $organization = new Organization('Tenant');
        $framework = new Framework('TISAX / VDA ISA', '6');
        $assessment = new ComplianceAssessment($organization, $framework, new Scope('Site', 'ORGANIZATION', $organization), new User('a@example.test', 'A', 'B', $organization, [User::ROLE_RISK_MANAGER]), new \DateTimeImmutable());
        $chapter = new Requirement($framework, 'CH-1', 'Chapitre', 'Sécurité de l’information');
        $results = [];
        foreach ([['1.1', 'Sécurité de l’information', $chapter], ['1.2', 'Sécurité de l’information', $chapter], ['8.1', 'Protection des prototypes', null]] as [$reference, $category, $parent]) {
            $requirement = (new Requirement($framework, $reference, 'Section '.$reference, $category))->setParentRequirement($parent);
            $results[$reference] = new ComplianceResult($assessment, $requirement);
        }
        $results['CH-1'] = new ComplianceResult($assessment, $chapter);

        return [$assessment, $results];
    }

    public function testOnlyLeafRequirementsAreScoredAgainstTheirTargetMaturity(): void
    {
        [$assessment, $results] = $this->fixture();
        $results['1.1']->setMaturityLevel(3)->setComplianceStatus('COMPLIANT');
        $results['1.2']->setMaturityLevel(2)->setComplianceStatus('PARTIAL');
        $results['8.1']->setMaturityLevel(4)->setComplianceStatus('COMPLIANT')->setTargetMaturity(4);

        $summary = (new TisaxAssessmentSummary())->summarize($assessment, array_values($results));

        self::assertSame(3, $summary['totals']['total'], 'The parent chapter is a grouping, not an assessed requirement.');
        self::assertSame(2, $summary['totals']['meets']);
        self::assertSame(1, $summary['totals']['blocking']);
        $gaps = array_column(array_column($summary['modules'], 'gaps')[0], null, 'reference');
        self::assertSame(['maturity' => 2, 'target' => 3], ['maturity' => $gaps['1.2']['maturity'], 'target' => $gaps['1.2']['target']]);
        self::assertFalse($summary['ready']);
    }

    public function testUnassessedRequirementsAreIncompleteAndNotApplicableOnesAreExcluded(): void
    {
        [$assessment, $results] = $this->fixture();
        $results['1.1']->setMaturityLevel(5)->setComplianceStatus('COMPLIANT');
        $results['1.2']->setComplianceStatus('NOT_APPLICABLE');
        // 8.1 stays NOT_ASSESSED

        $summary = (new TisaxAssessmentSummary())->summarize($assessment, array_values($results));

        self::assertSame(2, $summary['totals']['total']);
        self::assertSame(1, $summary['totals']['incomplete']);
        self::assertFalse($summary['ready'], 'An unassessed requirement is never counted as met.');
    }

    public function testReadyNeedsACompleteDeclarationAndNoGap(): void
    {
        [$assessment, $results] = $this->fixture();
        foreach (['1.1', '1.2', '8.1'] as $reference) {
            $results[$reference]->setMaturityLevel(3)->setComplianceStatus('COMPLIANT');
        }
        $service = new TisaxAssessmentSummary();
        self::assertFalse($service->summarize($assessment, array_values($results))['ready'], 'Level, objectives and site scope are required.');

        $assessment->configureTisax('AL3', ['INFO_HIGH', 'INFO_HIGH', 'PROTO_PARTS'], 'Site de Stuttgart', 'ABC123');
        $summary = $service->summarize($assessment, array_values($results));
        self::assertTrue($summary['ready']);
        self::assertSame(['INFO_HIGH', 'PROTO_PARTS'], $summary['objectives']);
        self::assertSame('AL3', $summary['level']);
    }

    public function testInvalidTisaxDeclarationIsRejected(): void
    {
        [$assessment] = $this->fixture();
        foreach ([['AL1', [], null, null], ['AL2', ['UNKNOWN'], null, null], ['AL2', [], null, 'bad id!']] as [$level, $objectives, $scope, $participant]) {
            try {
                $assessment->configureTisax($level, $objectives, $scope, $participant);
                self::fail('Invalid TISAX data must be rejected.');
            } catch (\InvalidArgumentException) {
                self::assertNull($assessment->getTisaxLevel());
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        (new ComplianceResult($assessment, new Requirement($assessment->getFramework(), 'X', 'X', 'X')))->setTargetMaturity(6);
    }
}
