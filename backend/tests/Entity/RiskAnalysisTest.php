<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\AnalysisArtifact;
use App\Entity\Organization;
use App\Entity\RiskAnalysis;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class RiskAnalysisTest extends TestCase
{
    public function testBaselineAndArtifactsRequireIndependentApproval(): void
    {
        $o = new Organization('Tenant');
        $author = new User('author@test.local', 'Risk', 'Author', $o);
        $approver = new User('approver@test.local', 'Risk', 'Approver', $o);
        $a = new RiskAnalysis($o, $author, 'analysis.core', 1, 'EBIOS_RM', 'Core', ['objectives' => ['Protect'], 'team' => [2], 'scenarioIds' => [1]]);
        $a->review([], 100);
        $a->approve($approver, ['scenarioIds' => [1]]);
        self::assertSame('APPROVED', $a->getStatus());
        self::assertSame([1], $a->getBaseline()['scenarioIds']);
        $e = new AnalysisArtifact($o, $a, $author, 'EVIDENCE', 'Audit evidence', ['sha256' => 'abc'], 'evidence-1');
        $e->approve($approver);
        self::assertSame('APPROVED',$e->getStatus());
    }
}
