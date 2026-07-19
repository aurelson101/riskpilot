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

final class ThirdPartyControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private JWTTokenManagerInterface $tokens;
    private User $manager;
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
        $this->foreign = new User('foreign@example.test', 'François', 'Externe', $other, [User::ROLE_ADMIN]);
        foreach ([$organization, $other, $this->manager, $this->foreign] as $entity) {
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
