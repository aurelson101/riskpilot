<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Organization;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ThirdPartyControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private JWTTokenManagerInterface $tokens;
    private User $manager;
    private User $viewer;
    private User $foreign;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $metadata = $manager->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($manager);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $organization = new Organization('Organisation');
        $other = new Organization('Autre');
        $this->manager = new User('manager@example.test', 'Marie', 'Risques', $organization, [User::ROLE_RISK_MANAGER]);
        $this->viewer = new User('viewer@example.test', 'Victor', 'Lecture', $organization, [User::ROLE_VIEWER]);
        $this->foreign = new User('foreign@example.test', 'François', 'Externe', $other, [User::ROLE_ADMIN]);
        foreach ([$organization, $other, $this->manager, $this->viewer, $this->foreign] as $entity) {
            $manager->persist($entity);
        } $manager->flush();
        $this->tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
    }

    public function testSupplierQuestionnairePortalAndReview(): void
    {
        $this->authenticate($this->manager);
        $this->client->jsonRequest('POST', '/api/third-parties', ['name' => 'Cloud SA', 'contactEmail' => 'security@cloud.test', 'services' => 'Hébergement', 'dataCategories' => ['clients'], 'criticality' => 'CRITICAL', 'status' => 'ACTIVE', 'ownerId' => $this->manager->getId(), 'exitPlan' => 'Export et réversibilité']);
        self::assertResponseStatusCodeSame(201);
        $thirdParty = $this->payload();
        $this->client->jsonRequest('POST', '/api/third-parties/'.$thirdParty['id'].'/assessments', ['reviewerId' => $this->manager->getId(), 'title' => 'Évaluation 2026', 'version' => 2, 'expiresAt' => (new \DateTimeImmutable('+30 days'))->format(DATE_ATOM), 'questions' => [['id' => 'q1', 'label' => 'MFA activé ?', 'weight' => 5], ['id' => 'q2', 'label' => 'PRA testé ?', 'weight' => 5]]]);
        self::assertResponseStatusCodeSame(201);
        $assessment = $this->payload();
        $this->client->setServerParameter('HTTP_AUTHORIZATION', '');
        $this->client->request('GET', '/api/public/supplier-assessments/'.$assessment['publicToken']);
        self::assertResponseIsSuccessful();
        self::assertCount(2, $this->payload()['questions']);
        $this->client->jsonRequest('POST', '/api/public/supplier-assessments/'.$assessment['publicToken'], ['responses' => ['q1' => true, 'q2' => true], 'evidence' => ['ISO27001.pdf']]);
        self::assertResponseIsSuccessful();
        self::assertSame('SUBMITTED', $this->payload()['status']);
        $this->authenticate($this->manager);
        $this->client->jsonRequest('POST', '/api/supplier-assessments/'.$assessment['id'].'/review', ['score' => 82, 'comment' => 'Preuves cohérentes']);
        self::assertResponseIsSuccessful();
        self::assertSame(82, $this->payload()['score']);
        $this->client->request('GET', '/api/third-parties');
        self::assertSame(82, $this->payload()[0]['cyberScore']);
    }

    public function testTenantRelationsAreRejected(): void
    {
        $this->authenticate($this->foreign);
        $this->client->jsonRequest('POST', '/api/third-parties', ['name' => 'Tiers', 'criticality' => 'HIGH', 'ownerId' => $this->manager->getId()]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testCreateAndUpdateThirdPartyWithStrictContractDates(): void
    {
        $this->authenticate($this->manager);
        $this->client->jsonRequest('POST', '/api/third-parties', [
            ...$this->thirdPartyInput('Prestataire contrat'),
            'contractReference' => 'CTR-2026-01',
            'contractEndsAt' => '2027-12-31',
            'nextAssessmentAt' => '2026-12-15',
        ]);
        self::assertResponseStatusCodeSame(201);
        $created = $this->payload();
        self::assertSame('CTR-2026-01', $created['contractReference']);
        self::assertSame('2027-12-31', $created['contractEndsAt']);
        self::assertSame('2026-12-15', $created['nextAssessmentAt']);

        $this->client->jsonRequest('PUT', '/api/third-parties/'.$created['id'], [
            ...$this->thirdPartyInput('Prestataire contrat actualisé'),
            'contractReference' => 'CTR-2027-02',
            'contractEndsAt' => null,
            'nextAssessmentAt' => '2027-06-30',
        ]);
        self::assertResponseIsSuccessful();
        $updated = $this->payload();
        self::assertSame('Prestataire contrat actualisé', $updated['name']);
        self::assertSame('CTR-2027-02', $updated['contractReference']);
        self::assertNull($updated['contractEndsAt']);
        self::assertSame('2027-06-30', $updated['nextAssessmentAt']);
    }

    #[DataProvider('invalidDateProvider')]
    public function testInvalidDatesAreRejectedWithoutPartialUpdate(string $field, mixed $invalid): void
    {
        $this->authenticate($this->manager);
        $this->client->jsonRequest('POST', '/api/third-parties', [
            ...$this->thirdPartyInput('Prestataire inchangé'),
            'contractReference' => 'CTR-STABLE',
            'contractEndsAt' => '2027-12-31',
            'nextAssessmentAt' => '2026-12-15',
        ]);
        self::assertResponseStatusCodeSame(201);
        $created = $this->payload();

        $this->client->jsonRequest('PUT', '/api/third-parties/'.$created['id'], [
            ...$this->thirdPartyInput('Modification à refuser'),
            'contractReference' => 'CTR-MODIFIED',
            'contractEndsAt' => '2028-01-31',
            'nextAssessmentAt' => '2028-02-01',
            $field => $invalid,
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('INVALID_INPUT', $this->payload()['code']);

        $this->client->request('GET', '/api/third-parties');
        self::assertResponseIsSuccessful();
        $stored = $this->payload()[0];
        self::assertSame('Prestataire inchangé', $stored['name']);
        self::assertSame('CTR-STABLE', $stored['contractReference']);
        self::assertSame('2027-12-31', $stored['contractEndsAt']);
        self::assertSame('2026-12-15', $stored['nextAssessmentAt']);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidDateProvider(): iterable
    {
        yield 'jour inexistant' => ['contractEndsAt', '2026-02-30'];
        yield 'date relative' => ['contractEndsAt', 'tomorrow'];
        yield 'booléen' => ['contractEndsAt', true];
        yield 'tableau' => ['nextAssessmentAt', []];
        yield 'date avec heure' => ['nextAssessmentAt', '2026-12-15T10:00:00+00:00'];
    }

    public function testViewerCannotManageThirdParties(): void
    {
        $this->authenticate($this->viewer);
        $this->client->jsonRequest('POST', '/api/third-parties', $this->thirdPartyInput('Interdit'));
        self::assertResponseStatusCodeSame(403);
        self::assertSame('FORBIDDEN', $this->payload()['code']);
    }

    public function testThirdPartyFromAnotherOrganizationCannotBeUpdated(): void
    {
        $this->authenticate($this->manager);
        $this->client->jsonRequest('POST', '/api/third-parties', $this->thirdPartyInput('Tiers local'));
        self::assertResponseStatusCodeSame(201);
        $created = $this->payload();

        $this->authenticate($this->foreign);
        $this->client->jsonRequest('PUT', '/api/third-parties/'.$created['id'], [
            'name' => 'Tentative étrangère',
            'criticality' => 'HIGH',
            'status' => 'ACTIVE',
            'ownerId' => $this->foreign->getId(),
        ]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('NOT_FOUND', $this->payload()['code']);
    }

    private function authenticate(User $user): void
    {
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$this->tokens->create($user));
    }

    /** @return array<string, mixed> */
    private function thirdPartyInput(string $name): array
    {
        return [
            'name' => $name,
            'criticality' => 'HIGH',
            'status' => 'ACTIVE',
            'ownerId' => $this->manager->getId(),
        ];
    }

    /** @return array<mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
