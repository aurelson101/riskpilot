<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Organization;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RegulatoryControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private JWTTokenManagerInterface $tokens;
    private User $manager;
    private User $admin;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($manager);
        $metadata = $manager->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $organization = new Organization('Organisation');
        $this->manager = new User('manager@example.test', 'Marie', 'Privacy', $organization, [User::ROLE_RISK_MANAGER]);
        $this->admin = new User('admin@example.test', 'Alice', 'Admin', $organization, [User::ROLE_ADMIN]);
        foreach ([$organization, $this->manager, $this->admin] as $entity) {
            $manager->persist($entity);
        } $manager->flush();
        $this->tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
    }

    public function testProcessingRegisterAndExceptionApproval(): void
    {
        $this->authenticate($this->manager);
        $this->client->jsonRequest('POST', '/api/regulatory-records', ['type' => 'PROCESSING_ACTIVITY', 'title' => 'Gestion clients', 'ownerId' => $this->manager->getId(), 'details' => ['purpose' => 'Contrat', 'dataCategories' => ['identité'], 'legalBasis' => 'Contrat', 'retention' => '5 ans', 'recipients' => ['Support']], 'evidence' => ['ROPA-01']]);
        self::assertResponseStatusCodeSame(201);
        $this->client->jsonRequest('POST', '/api/regulatory-records', ['type' => 'EXCEPTION', 'title' => 'Dérogation chiffrement', 'ownerId' => $this->manager->getId(), 'expiresAt' => (new \DateTimeImmutable('+30 days'))->format('Y-m-d'), 'details' => ['justification' => 'Legacy', 'risk' => 'Confidentialité', 'compensatingMeasure' => 'Segmentation']]);
        self::assertResponseStatusCodeSame(201);
        $exception = $this->payload();
        $this->client->jsonRequest('POST', '/api/regulatory-records/'.$exception['id'].'/approve');
        self::assertResponseStatusCodeSame(403);
        $this->authenticate($this->admin);
        $this->client->jsonRequest('POST', '/api/regulatory-records/'.$exception['id'].'/approve');
        self::assertResponseIsSuccessful();
        self::assertSame('APPROVED', $this->payload()['status']);
    }

    public function testRequiredPrivacyFieldsAreEnforced(): void
    {
        $this->authenticate($this->manager);
        $this->client->jsonRequest('POST', '/api/regulatory-records', ['type' => 'DPIA', 'title' => 'AIPD', 'ownerId' => $this->manager->getId(), 'details' => ['processing' => 'Profilage']]);
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
