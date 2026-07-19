<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Asset;
use App\Entity\Organization;
use App\Entity\Scope;
use App\Entity\Threat;
use App\Entity\User;
use App\Entity\Vulnerability;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\DataProvider;
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
    /** @var array<string, int> */
    private array $foreignResourceIds = [];
    private int $localScopeId;

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

        $scopeA = new Scope('Périmètre A', 'DEPARTMENT', $organizationA);
        $scopeB = new Scope('Périmètre B', 'SITE', $this->organizationB);
        $assetA = new Asset('Actif A', 'APPLICATION', $scopeA, $organizationA);
        $assetB = new Asset('Actif B', 'SERVER', $scopeB, $this->organizationB);
        $threatA = new Threat('Menace A', 'HUMAN', $organizationA);
        $threatB = new Threat('Menace B', 'TECHNICAL', $this->organizationB);
        $vulnerabilityA = new Vulnerability('Vulnérabilité A', 'CONFIGURATION', 'MEDIUM', $organizationA);
        $vulnerabilityB = new Vulnerability('Vulnérabilité B', 'PATCH', 'HIGH', $this->organizationB);

        foreach ([$organizationA, $this->organizationB, $this->adminA, $this->userA, $this->userB, $scopeA, $scopeB, $assetA, $assetB, $threatA, $threatB, $vulnerabilityA, $vulnerabilityB] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $this->foreignResourceIds = [
            'scopes' => (int) $scopeB->getId(),
            'assets' => (int) $assetB->getId(),
            'threats' => (int) $threatB->getId(),
            'vulnerabilities' => (int) $vulnerabilityB->getId(),
        ];
        $this->localScopeId = (int) $scopeA->getId();
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

    #[DataProvider('inventoryResources')]
    public function testInventoryListsNeverExposeAnotherTenant(string $resource): void
    {
        $this->client->request('GET', '/api/'.$resource);
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $payload, $resource.' must be tenant scoped');
    }

    #[DataProvider('inventoryResources')]
    public function testForeignInventoryResourcesAreNotAddressable(string $resource): void
    {
        $this->client->request('GET', sprintf('/api/%s/%d', $resource, $this->foreignResourceIds[$resource]));
        self::assertResponseStatusCodeSame(404, $resource.' must hide foreign resources');
    }

    public function testAssetCannotReferenceForeignScope(): void
    {
        $this->client->request('POST', '/api/assets', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'name' => 'Actif interdit',
            'type' => 'SERVER',
            'scopeId' => $this->foreignResourceIds['scopes'],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
    }

    public function testAssetCannotReferenceForeignRelatedAsset(): void
    {
        $this->client->request('POST', '/api/assets', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'name' => 'Relation interdite',
            'type' => 'APPLICATION',
            'scopeId' => $this->localScopeId,
            'relatedAssetIds' => [$this->foreignResourceIds['assets']],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
    }

    /** @return iterable<string, array{string}> */
    public static function inventoryResources(): iterable
    {
        foreach (['scopes', 'assets', 'threats', 'vulnerabilities'] as $resource) {
            yield $resource => [$resource];
        }
    }
}
