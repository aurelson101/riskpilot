<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Asset;
use App\Entity\Organization;
use App\Entity\RiskGovernancePolicy;
use App\Entity\RiskScenario;
use App\Entity\Scope;
use App\Entity\Threat;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RiskGovernanceControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private JWTTokenManagerInterface $tokens;
    private User $manager;
    private User $admin;
    private User $reviewer;
    private RiskScenario $risk;
    private int $foreignPolicyId;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $metadata = $manager->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($manager);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $organization = new Organization('Organisation gouvernée');
        $foreign = new Organization('Organisation étrangère');
        $this->manager = new User('manager@example.test', 'Marie', 'Risques', $organization, [User::ROLE_RISK_MANAGER]);
        $this->admin = new User('admin@example.test', 'Alice', 'Admin', $organization, [User::ROLE_ADMIN]);
        $this->reviewer = new User('reviewer@example.test', 'Rémi', 'Revue', $organization, [User::ROLE_VIEWER]);
        $foreignUser = new User('foreign@example.test', 'François', 'Externe', $foreign, [User::ROLE_ADMIN]);
        $scope = new Scope('Production', 'DEPARTMENT', $organization);
        $asset = new Asset('ERP', 'APPLICATION', $scope, $organization);
        $threat = new Threat('Rançongiciel', 'TECHNICAL', $organization);
        $this->risk = (new RiskScenario('Indisponibilité ERP', $organization, $scope, $asset, $threat, $this->manager))->configureGovernance('CYBER', 'ISO_27005', true)->setEvaluations(5, 5, 25, 4, 4, 16, 3, 4, 12);
        $foreignPolicy = new RiskGovernancePolicy($foreign, 'GLOBAL', 'GLOBAL', $foreignUser);
        $foreignPolicy->update('GLOBAL', 'GLOBAL', 4, 9, 16, 'SIMPLIFIED', null, $foreignUser);
        foreach ([$organization, $foreign, $this->manager, $this->admin, $this->reviewer, $foreignUser, $scope, $asset, $threat, $this->risk, $foreignPolicy] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();
        $this->foreignPolicyId = (int) $foreignPolicy->getId();
        $this->tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
    }

    public function testPolicyRecommendationAcceptanceAndReviewCampaignLifecycle(): void
    {
        $this->authenticate($this->manager);
        $this->json('POST', '/api/risk-governance/policies', ['domain' => 'Production', 'family' => 'CYBER', 'appetiteScore' => 4, 'toleranceScore' => 9, 'capacityScore' => 16, 'method' => 'ISO_27005', 'rationale' => 'Service essentiel', 'ownerId' => $this->manager->getId()]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('ISO_27005', $this->payload()['method']);

        $this->json('PUT', '/api/risks/'.$this->risk->getId(), ['title' => $this->risk->getTitle(), 'description' => null, 'family' => 'CYBER', 'analysisMethod' => 'ISO_27005', 'strategic' => true, 'methodData' => ['likelihoodRationale' => 'Historique', 'impactRationale' => 'Critique', 'controlRationale' => 'Partiel'], 'scopeId' => $this->risk->getScope()->getId(), 'assetId' => $this->risk->getAsset()->getId(), 'threatId' => $this->risk->getThreat()->getId(), 'riskOwnerId' => $this->manager->getId(), 'vulnerabilityIds' => [], 'currentControlIds' => [], 'likelihood' => 5, 'impact' => 5, 'currentLikelihood' => 4, 'currentImpact' => 4, 'residualLikelihood' => 3, 'residualImpact' => 4, 'treatmentDecision' => 'ACCEPT', 'status' => 'ACCEPTED', 'reviewDate' => null]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('FORMAL_ACCEPTANCE_REQUIRED', $this->payload()['code']);

        $this->client->request('GET', '/api/risk-governance/recommendations');
        self::assertResponseIsSuccessful();
        $recommendation = $this->payload()[0];
        self::assertSame('ABOVE_TOLERANCE', $recommendation['position']);
        self::assertSame('REDUCE', $recommendation['recommendedDecision']);
        self::assertSame(['estimatedCost' => 0, 'estimatedEffortDays' => 0, 'expectedReduction' => 0, 'coverageGap' => 3, 'reductionPerThousand' => null], $recommendation['treatment']);

        $this->json('POST', '/api/risk-governance/risks/'.$this->risk->getId().'/acceptances', ['justification' => 'Traitement différé par décision de direction', 'authority' => 'Direction générale', 'expiresAt' => (new \DateTimeImmutable('+90 days'))->format(DATE_ATOM), 'evidenceReference' => 'PV-CODIR-2026-07']);
        self::assertResponseStatusCodeSame(201);
        $acceptance = $this->payload();
        self::assertSame('PENDING', $acceptance['status']);

        $this->authenticate($this->admin);
        $this->json('POST', '/api/risk-governance/acceptances/'.$acceptance['id'].'/decision', ['status' => 'APPROVED', 'comment' => 'Acceptation limitée à 90 jours']);
        self::assertResponseIsSuccessful();
        self::assertSame('APPROVED', $this->payload()['status']);

        $this->authenticate($this->manager);
        $this->json('POST', '/api/risk-governance/campaigns', ['title' => 'Revue trimestrielle', 'description' => 'Revue du périmètre critique', 'status' => 'ACTIVE', 'startsAt' => (new \DateTimeImmutable('today'))->format(DATE_ATOM), 'dueAt' => (new \DateTimeImmutable('+30 days'))->format(DATE_ATOM), 'reviewerId' => $this->reviewer->getId(), 'riskIds' => [$this->risk->getId()]]);
        self::assertResponseStatusCodeSame(201);
        $campaign = $this->payload();
        self::assertSame(['completed' => 0, 'total' => 1], $campaign['progress']);

        $this->authenticate($this->reviewer);
        $this->json('POST', '/api/risk-governance/reviews/'.$campaign['reviews'][0]['id'].'/complete', ['reviewedScore' => 10, 'comment' => 'Mesures compensatoires vérifiées']);
        self::assertResponseIsSuccessful();
        self::assertSame(-6, $this->payload()['delta']);

        $this->client->request('GET', '/api/risk-governance/campaigns');
        self::assertResponseIsSuccessful();
        self::assertSame(['completed' => 1, 'total' => 1], $this->payload()[0]['progress']);
    }

    public function testViewerCannotCreatePolicyAndTenantResourcesAreNotAddressable(): void
    {
        $this->authenticate($this->reviewer);
        $this->json('POST', '/api/risk-governance/policies', ['domain' => 'GLOBAL']);
        self::assertResponseStatusCodeSame(403);

        $this->authenticate($this->manager);
        $this->json('PUT', '/api/risk-governance/policies/'.$this->foreignPolicyId, ['domain' => 'GLOBAL']);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/api/risk-governance/policies');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->payload());
    }

    private function authenticate(User $user): void
    {
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$this->tokens->create($user));
    }

    /** @param array<string, mixed> $payload */
    private function json(string $method, string $uri, array $payload): void
    {
        $this->client->jsonRequest($method, $uri, $payload);
    }

    /** @return array<mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
