<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Organization;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TenantIsolationTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $adminA;
    private User $userA;
    private User $userB;
    private Organization $organizationB;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $organizationA = new Organization('Organisation A');
        $this->organizationB = new Organization('Organisation B');
        $this->adminA = new User('admin-a@example.test', 'Alice', 'Admin', $organizationA, [User::ROLE_ADMIN]);
        $this->userA = new User('user-a@example.test', 'Alain', 'A', $organizationA, [User::ROLE_VIEWER]);
        $this->userB = new User('user-b@example.test', 'Brice', 'B', $this->organizationB, [User::ROLE_VIEWER]);

        foreach ([$organizationA, $this->organizationB, $this->adminA, $this->userA, $this->userB] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $this->client->loginUser($this->adminA, 'api');
    }

    public function testUserListOnlyContainsCurrentOrganization(): void
    {
        $this->client->request('GET', '/api/users');

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(2, $payload);
        self::assertEqualsCanonicalizing(
            ['admin-a@example.test', 'user-a@example.test'],
            array_column($payload, 'email'),
        );
    }

    public function testUserFromAnotherOrganizationIsNotAddressable(): void
    {
        $this->client->request('GET', '/api/users/'.$this->userB->getId());

        self::assertResponseStatusCodeSame(404);
    }

    public function testOrganizationFromAnotherTenantIsNotAddressable(): void
    {
        $this->client->request('GET', '/api/organizations/'.$this->organizationB->getId());

        self::assertResponseStatusCodeSame(404);
    }

    public function testViewerCannotAccessAdministration(): void
    {
        $this->client->loginUser($this->userA, 'api');
        $this->client->request('GET', '/api/users');

        self::assertResponseStatusCodeSame(403);
    }
}
