<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ExecutiveGovernanceRecord;
use App\Entity\Organization;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class ExecutiveGovernanceRecordIsmsTest extends TestCase
{
    private function owner(): User
    {
        return new User('owner@example.test', 'O', 'W', new Organization('Tenant'), [User::ROLE_RISK_MANAGER]);
    }

    public function testManagementSystemContextRecordsAreValidated(): void
    {
        $owner = $this->owner();
        $issue = new ExecutiveGovernanceRecord($owner->getOrganization(), $owner, 'CONTEXT_ISSUE', 'Évolution réglementaire', ['kind' => 'EXTERNAL', 'description' => 'NIS2', 'impact' => 'Mesures à renforcer'], 'ACTIVE', null);
        self::assertSame('CONTEXT_ISSUE', $issue->getType());

        $this->expectException(\InvalidArgumentException::class);
        new ExecutiveGovernanceRecord($owner->getOrganization(), $owner, 'CONTEXT_ISSUE', 'Enjeu', ['kind' => 'OTHER', 'description' => 'x', 'impact' => 'y'], 'ACTIVE', null);
    }

    public function testInterestedPartyCompetenceAndAwarenessRecordsRequireTheirFields(): void
    {
        $owner = $this->owner();
        $organization = $owner->getOrganization();
        new ExecutiveGovernanceRecord($organization, $owner, 'INTERESTED_PARTY', 'Clients', ['party' => 'Clients', 'expectations' => 'Disponibilité', 'legalRequirement' => true], 'ACTIVE', null);
        new ExecutiveGovernanceRecord($organization, $owner, 'COMPETENCE_RECORD', 'RSSI', ['subject' => 'RSSI', 'requiredCompetence' => 'ISO 27001 LI', 'evidence' => 'Certificat'], 'ACTIVE', null);
        $campaign = new ExecutiveGovernanceRecord($organization, $owner, 'AWARENESS_CAMPAIGN', 'Hameçonnage', ['audience' => 'Tous', 'topic' => 'Phishing', 'date' => '2026-11-01', 'completionRate' => 80], 'COMPLETED', null);
        self::assertSame('AWARENESS_CAMPAIGN', $campaign->getType());

        $this->expectException(\InvalidArgumentException::class);
        new ExecutiveGovernanceRecord($organization, $owner, 'AWARENESS_CAMPAIGN', 'Hameçonnage', ['audience' => 'Tous', 'topic' => 'Phishing', 'date' => '2026-11-01', 'completionRate' => 140], 'ACTIVE', null);
    }

    public function testApprovedManagementReviewNeedsTheNineThreeTwoInputs(): void
    {
        $owner = $this->owner();
        $base = ['date' => '2026-11-01', 'participants' => ['DG'], 'decisions' => ['Valider le plan'], 'actions' => []];
        $draft = new ExecutiveGovernanceRecord($owner->getOrganization(), $owner, 'MANAGEMENT_REVIEW', 'Revue annuelle', $base + ['inputs' => ['auditResults' => 'Audit interne T3']], 'DRAFT', null);

        try {
            $draft->update('Revue annuelle', $base + ['inputs' => ['auditResults' => 'Audit interne T3']], 'APPROVED', null, $owner);
            self::fail('An incomplete review cannot be approved.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('previousActions', $error->getMessage());
            self::assertStringContainsString('improvementOpportunities', $error->getMessage());
        }

        $inputs = array_fill_keys(ExecutiveGovernanceRecord::REVIEW_INPUTS, 'Renseigné');
        $draft->update('Revue annuelle', $base + ['inputs' => $inputs], 'APPROVED', null, $owner);
        self::assertSame('APPROVED', $draft->getStatus());

        // Une revue déjà approuvée reste modifiable : la règle ne s'applique qu'au passage à « approuvé ».
        $draft->update('Revue annuelle (correction)', $base + ['inputs' => $inputs], 'APPROVED', null, $owner);
        self::assertSame('Revue annuelle (correction)', $draft->getTitle());
    }
}
