<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Framework;
use App\Entity\Organization;
use App\Entity\Requirement;
use App\Entity\Scope;
use App\Entity\SecurityControl;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ComplianceGovernanceControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private JWTTokenManagerInterface $tokens;
    private User $manager;
    private User $admin;
    private User $foreignAdmin;
    private Framework $framework;
    private Requirement $requirement;
    private Scope $scope;
    private SecurityControl $control;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $metadata = $manager->getMetadataFactory()->getAllMetadata();
        $schema = new SchemaTool($manager);
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);
        $organization = new Organization('Organisation certifiée');
        $foreign = new Organization('Organisation étrangère');
        $this->manager = new User('risk@example.test', 'Marie', 'Risques', $organization, [User::ROLE_RISK_MANAGER]);
        $this->admin = new User('admin@example.test', 'Alice', 'Admin', $organization, [User::ROLE_ADMIN]);
        $this->foreignAdmin = new User('foreign@example.test', 'François', 'Externe', $foreign, [User::ROLE_ADMIN]);
        $this->framework = new Framework('ISO 27001', '2022');
        $this->requirement = new Requirement($this->framework, 'A.5.1', 'Politiques de sécurité', 'Organisation');
        $secondRequirement = new Requirement($this->framework, 'A.5.2', 'Rôles et responsabilités', 'Organisation');
        $this->scope = new Scope('SMSI principal', 'ORGANIZATION', $organization);
        $this->control = new SecurityControl('Revue des politiques', 'Gouvernance', $organization);
        foreach ([$organization, $foreign, $this->manager, $this->admin, $this->foreignAdmin, $this->framework, $this->requirement, $secondRequirement, $this->scope, $this->control] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();
        $this->tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
    }

    public function testVersionedStatementAndControlTestLifecycle(): void
    {
        $this->authenticate($this->manager);
        $this->client->jsonRequest('POST', '/api/statements-of-applicability', ['title' => 'SoA ISO 27001', 'frameworkId' => $this->framework->getId(), 'scopeId' => $this->scope->getId(), 'ownerId' => $this->manager->getId()]);
        self::assertResponseStatusCodeSame(201);
        $statement = $this->payload();
        self::assertSame(2, $statement['itemCount']);

        $this->client->request('GET', '/api/statements-of-applicability/'.$statement['id']);
        $detail = $this->payload();
        $this->client->jsonRequest('PUT', '/api/statements-of-applicability/'.$statement['id'].'/items/'.$detail['items'][0]['id'], ['applicable' => true, 'implementationStatus' => 'IMPLEMENTED', 'ownerId' => $this->manager->getId(), 'controlIds' => [$this->control->getId()], 'riskIds' => [], 'actionIds' => [], 'evidence' => ['POL-001'], 'nextReviewAt' => '2027-01-01']);
        self::assertResponseIsSuccessful();
        self::assertSame('POL-001', $this->payload()['evidence'][0]);

        $this->authenticate($this->admin);
        $this->client->jsonRequest('POST', '/api/statements-of-applicability/'.$statement['id'].'/approve');
        self::assertResponseIsSuccessful();
        self::assertSame('APPROVED', $this->payload()['status']);
        $this->client->jsonRequest('POST', '/api/statements-of-applicability/'.$statement['id'].'/revise');
        self::assertResponseStatusCodeSame(201);
        self::assertSame(2, $this->payload()['version']);

        $this->client->jsonRequest('POST', '/api/control-tests', ['controlId' => $this->control->getId(), 'testerId' => $this->admin->getId(), 'type' => 'OPERATING_EFFECTIVENESS', 'frequency' => 'ANNUAL', 'procedure' => 'Inspecter les approbations', 'performedAt' => '2026-07-19', 'nextReviewAt' => '2027-07-19', 'result' => 'EFFECTIVE', 'sampleSize' => 12, 'evidence' => ['AUDIT-12']]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('EFFECTIVE', $this->payload()['result']);
    }

    public function testTenantIsolationAndMappingValidation(): void
    {
        $this->authenticate($this->foreignAdmin);
        $this->client->jsonRequest('POST', '/api/statements-of-applicability', ['frameworkId' => $this->framework->getId(), 'scopeId' => $this->scope->getId(), 'ownerId' => $this->manager->getId()]);
        self::assertResponseStatusCodeSame(422);

        $this->authenticate($this->manager);
        $this->client->jsonRequest('POST', '/api/requirement-mappings', ['sourceRequirementId' => $this->requirement->getId(), 'targetRequirementId' => $this->requirement->getId(), 'coveragePercent' => 100]);
        self::assertResponseStatusCodeSame(422);
    }

    private function authenticate(User $user): void
    {
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$this->tokens->create($user));
    }

    /** @return array<mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
