<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Organization;
use App\Entity\Scope;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AuditManagementControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private JWTTokenManagerInterface $tokens;
    private User $auditor;
    private User $owner;
    private User $admin;
    private User $foreignAdmin;
    private Scope $scope;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $metadata = $manager->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($manager);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $organization = new Organization('Organisation auditée');
        $foreign = new Organization('Organisation externe');
        $this->auditor = new User('auditor@example.test', 'Anne', 'Audit', $organization, [User::ROLE_AUDITOR]);
        $this->owner = new User('owner@example.test', 'Olivier', 'Pilote', $organization, [User::ROLE_ACTION_OWNER]);
        $this->admin = new User('admin@example.test', 'Alice', 'Admin', $organization, [User::ROLE_ADMIN]);
        $this->foreignAdmin = new User('foreign@example.test', 'François', 'Externe', $foreign, [User::ROLE_ADMIN]);
        $this->scope = new Scope('Production', 'DEPARTMENT', $organization);
        foreach ([$organization, $foreign, $this->auditor, $this->owner, $this->admin, $this->foreignAdmin, $this->scope] as $entity) {
            $manager->persist($entity);
        } $manager->flush();
        $this->tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
    }

    public function testAuditProgramFindingAndIndependentCapaValidation(): void
    {
        $this->authenticate($this->auditor);
        $this->client->jsonRequest('POST', '/api/audit-management/programs', ['year' => 2026, 'title' => 'Programme annuel 2026', 'objectives' => 'Couvrir le SMSI', 'status' => 'ACTIVE', 'ownerId' => $this->auditor->getId()]);
        self::assertResponseStatusCodeSame(201);
        $program = $this->payload();
        $this->client->jsonRequest('POST', '/api/audit-management/programs/'.$program['id'].'/engagements', ['scopeId' => $this->scope->getId(), 'leadAuditorId' => $this->auditor->getId(), 'teamIds' => [$this->auditor->getId()], 'title' => 'Audit des accès', 'independenceStatement' => 'Aucun membre n’a de responsabilité opérationnelle sur le périmètre.', 'startsAt' => '2026-09-01', 'endsAt' => '2026-09-05']);
        self::assertResponseStatusCodeSame(201);
        $engagement = $this->payload();
        $this->client->jsonRequest('POST', '/api/audit-management/engagements/'.$engagement['id'].'/findings', ['ownerId' => $this->owner->getId(), 'type' => 'MAJOR_NONCONFORMITY', 'title' => 'Revues des accès absentes', 'description' => 'Aucune revue semestrielle démontrée.', 'evidence' => ['AUD-2026-001'], 'dueAt' => '2026-10-01']);
        self::assertResponseStatusCodeSame(201);
        $finding = $this->payload();
        $this->authenticate($this->owner);
        $this->client->jsonRequest('PUT', '/api/audit-management/findings/'.$finding['id'].'/capa', ['rootCause' => 'Responsabilité non attribuée', 'correction' => 'Revue immédiate', 'correctiveAction' => 'Workflow semestriel', 'preventiveAction' => 'KPI de complétude']);
        self::assertResponseIsSuccessful();
        self::assertSame('ACTION_IN_PROGRESS', $this->payload()['status']);
        $this->client->jsonRequest('POST', '/api/audit-management/findings/'.$finding['id'].'/effectiveness-review');
        self::assertResponseIsSuccessful();
        $this->client->jsonRequest('POST', '/api/audit-management/findings/'.$finding['id'].'/effectiveness-decision', ['effective' => true, 'conclusion' => 'Incorrectement auto-validé']);
        self::assertResponseStatusCodeSame(403);
        $this->authenticate($this->admin);
        $this->client->jsonRequest('POST', '/api/audit-management/findings/'.$finding['id'].'/effectiveness-decision', ['effective' => true, 'conclusion' => 'Deux cycles vérifiés sans écart']);
        self::assertResponseIsSuccessful();
        self::assertSame('CLOSED', $this->payload()['status']);
        $this->client->request('GET', '/api/audit-management/dashboard');
        self::assertResponseIsSuccessful();
        self::assertSame(0, $this->payload()['openFindings']);
    }

    public function testTenantAndRoleIsolation(): void
    {
        $this->authenticate($this->owner);
        $this->client->jsonRequest('POST', '/api/audit-management/programs', ['year' => 2026]);
        self::assertResponseStatusCodeSame(403);
        $this->authenticate($this->foreignAdmin);
        $this->client->jsonRequest('POST', '/api/audit-management/programs', ['year' => 2026, 'title' => 'Étranger', 'ownerId' => $this->auditor->getId()]);
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
