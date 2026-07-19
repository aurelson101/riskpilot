<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ActionPlan;
use App\Entity\Asset;
use App\Entity\ComplianceAssessment;
use App\Entity\ComplianceResult;
use App\Entity\Framework;
use App\Entity\Notification;
use App\Entity\Organization;
use App\Entity\Requirement;
use App\Entity\RiskScenario;
use App\Entity\Scope;
use App\Entity\SecurityControl;
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
    private int $foreignNotificationId;
    private int $foreignComplianceResultId;

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
        $controlA = new SecurityControl('MFA A', 'ACCESS', $organizationA);
        $controlB = new SecurityControl('MFA B', 'ACCESS', $this->organizationB);
        $riskA = (new RiskScenario('Risque A', $organizationA, $scopeA, $assetA, $threatA, $this->adminA))->setEvaluations(5, 5, 25, 4, 4, 16, 2, 2, 4);
        $riskB = (new RiskScenario('Risque B', $this->organizationB, $scopeB, $assetB, $threatB, $this->userB))->setEvaluations(4, 5, 20, 3, 4, 12, 2, 3, 6);
        $actionA = new ActionPlan('Action A', $organizationA, $riskA, $this->adminA, new \DateTimeImmutable('+30 days'));
        $actionB = new ActionPlan('Action B', $this->organizationB, $riskB, $this->userB, new \DateTimeImmutable('+20 days'));
        $notificationA = new Notification($this->adminA, 'ACTION_ASSIGNED', 'Action A', 'Une action vous est affectée.');
        $notificationB = new Notification($this->userB, 'ACTION_ASSIGNED', 'Action B', 'Une action vous est affectée.');
        $framework = new Framework('Référentiel public', '1.0');
        $requirement = new Requirement($framework, 'ID-1', 'Gestion des identités', 'Protection');
        $assessmentA = new ComplianceAssessment($organizationA, $framework, $scopeA, $this->adminA, new \DateTimeImmutable());
        $assessmentB = new ComplianceAssessment($this->organizationB, $framework, $scopeB, $this->userB, new \DateTimeImmutable());
        $resultA = (new ComplianceResult($assessmentA, $requirement))->setComplianceStatus('COMPLIANT')->setMaturityLevel(4);
        $resultB = (new ComplianceResult($assessmentB, $requirement))->setComplianceStatus('NON_COMPLIANT')->setMaturityLevel(1);
        $assessmentA->recalculateScore();
        $assessmentB->recalculateScore();

        foreach ([$organizationA, $this->organizationB, $this->adminA, $this->userA, $this->userB, $scopeA, $scopeB, $assetA, $assetB, $threatA, $threatB, $vulnerabilityA, $vulnerabilityB, $controlA, $controlB, $riskA, $riskB, $actionA, $actionB, $notificationA, $notificationB, $framework, $requirement, $assessmentA, $assessmentB, $resultA, $resultB] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();
        $this->foreignResourceIds = [
            'scopes' => (int) $scopeB->getId(),
            'assets' => (int) $assetB->getId(),
            'threats' => (int) $threatB->getId(),
            'vulnerabilities' => (int) $vulnerabilityB->getId(),
            'security-controls' => (int) $controlB->getId(),
            'risks' => (int) $riskB->getId(),
            'actions' => (int) $actionB->getId(),
            'compliance-assessments' => (int) $assessmentB->getId(),
        ];
        $this->foreignNotificationId = (int) $notificationB->getId();
        $this->foreignComplianceResultId = (int) $resultB->getId();
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

    public function testAuthenticatedUserCanUpdateOwnProfile(): void
    {
        $this->client->request('PUT', '/api/me', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'firstName' => 'Alicia',
            'lastName' => 'Administratrice',
            'email' => 'alicia@example.test',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Alicia', $payload['firstName']);
        self::assertSame('Administratrice', $payload['lastName']);
        self::assertSame('alicia@example.test', $payload['email']);
    }

    public function testProfileEmailMustBeUnique(): void
    {
        $this->client->request('PUT', '/api/me', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'firstName' => 'Alice',
            'lastName' => 'Admin',
            'email' => 'user-b@example.test',
        ], JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(422);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('EMAIL_ALREADY_USED', $payload['code']);
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

    #[DataProvider('riskResources')]
    public function testRiskResourcesAreTenantScoped(string $resource): void
    {
        $this->client->request('GET', '/api/'.$resource);
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $payload);
    }

    #[DataProvider('riskResources')]
    public function testForeignRiskResourcesAreNotAddressable(string $resource): void
    {
        $this->client->request('GET', sprintf('/api/%s/%d', $resource, $this->foreignResourceIds[$resource]));
        self::assertResponseStatusCodeSame(404);
    }

    public function testRiskMatrixOnlyContainsCurrentTenant(): void
    {
        $this->client->request('GET', '/api/risk-matrix?scoreType=current');
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, array_sum(array_column($payload['cells'], 'count')));
    }

    /** @return iterable<string, array{string}> */
    public static function riskResources(): iterable
    {
        yield 'controls' => ['security-controls'];
        yield 'risks' => ['risks'];
    }

    public function testActionPlansAreTenantScoped(): void
    {
        $this->client->request('GET', '/api/actions');
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $payload);
        self::assertSame('Action A', $payload[0]['title']);
    }

    public function testForeignActionPlanIsNotAddressable(): void
    {
        $this->client->request('GET', '/api/actions/'.$this->foreignResourceIds['actions']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testNotificationsBelongOnlyToRecipient(): void
    {
        $this->client->request('GET', '/api/notifications');
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $payload);
        self::assertSame('Action A', $payload[0]['title']);
    }

    public function testForeignNotificationCannotBeMarkedRead(): void
    {
        $this->client->request('PUT', '/api/notifications/'.$this->foreignNotificationId.'/read');
        self::assertResponseStatusCodeSame(404);
    }

    public function testComplianceAssessmentsAreTenantScoped(): void
    {
        $this->client->request('GET', '/api/compliance-assessments');
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $payload);
        self::assertSame(100, $payload[0]['globalScore']);
    }

    public function testForeignComplianceAssessmentIsNotAddressable(): void
    {
        $this->client->request('GET', '/api/compliance-assessments/'.$this->foreignResourceIds['compliance-assessments']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testForeignComplianceResultCannotBeUpdated(): void
    {
        $this->client->request('PUT', '/api/compliance-results/'.$this->foreignComplianceResultId, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['maturityLevel' => 5, 'complianceStatus' => 'COMPLIANT'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(404);
    }

    public function testDashboardOnlyAggregatesCurrentTenant(): void
    {
        $this->client->request('GET', '/api/dashboard');
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $payload['summary']['totalRisks']);
        self::assertCount(1, $payload['topRisks']);
        self::assertSame('Risque A', $payload['topRisks'][0]['title']);
    }

    public function testRiskExportNeverContainsForeignTenant(): void
    {
        $this->client->request('GET', '/api/exports/risks.csv');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'text/csv; charset=UTF-8');
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Risque A', $content);
        self::assertStringNotContainsString('Risque B', $content);
    }
}
