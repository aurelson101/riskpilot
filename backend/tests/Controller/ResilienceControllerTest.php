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

final class ResilienceControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private JWTTokenManagerInterface $tokens;
    private User $manager;
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
        $organization = new Organization('Organisation résiliente');
        $this->manager = new User('manager@example.test', 'Marie', 'Crise', $organization, [User::ROLE_RISK_MANAGER]);
        $this->scope = new Scope('Production', 'DEPARTMENT', $organization);
        foreach ([$organization, $this->manager, $this->scope] as $entity) {
            $manager->persist($entity);
        } $manager->flush();
        $this->tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$this->tokens->create($this->manager));
    }

    public function testIncidentChronologyAndClosureGuard(): void
    {
        $this->client->jsonRequest('POST', '/api/resilience/incidents', ['ownerId' => $this->manager->getId(), 'title' => 'Indisponibilité', 'description' => 'Service arrêté', 'severity' => 'CRITICAL', 'detectedAt' => '2026-07-19T10:00:00+00:00']);
        self::assertResponseStatusCodeSame(201);
        $incident = $this->payload();
        self::assertCount(1, $incident['timeline']);
        $this->client->jsonRequest('POST', '/api/resilience/incidents/'.$incident['id'].'/timeline', ['event' => 'Service contenu']);
        self::assertResponseIsSuccessful();
        self::assertCount(2, $this->payload()['timeline']);
        $this->client->jsonRequest('PUT', '/api/resilience/incidents/'.$incident['id'], ['status' => 'CLOSED', 'impacts' => ['availabilityHours' => 3], 'evidence' => ['INC-001'], 'regulatoryNotificationRequired' => true]);
        self::assertResponseStatusCodeSame(422);
        $this->client->jsonRequest('PUT', '/api/resilience/incidents/'.$incident['id'], ['status' => 'CLOSED', 'impacts' => ['availabilityHours' => 3], 'evidence' => ['INC-001'], 'regulatoryNotificationRequired' => true, 'notifiedAt' => '2026-07-19T12:00:00+00:00', 'lessonsLearned' => 'Tester la bascule']);
        self::assertResponseIsSuccessful();
        self::assertSame('CLOSED', $this->payload()['status']);
    }

    public function testBiaObjectivesAndExercise(): void
    {
        $this->client->jsonRequest('POST', '/api/resilience/continuity-processes', ['scopeId' => $this->scope->getId(), 'ownerId' => $this->manager->getId(), 'name' => 'Commande client', 'criticality' => 'CRITICAL', 'mtpdHours' => 24, 'rtoHours' => 4, 'rpoHours' => 1, 'dependencies' => ['ERP', 'Cloud'], 'businessImpact' => 'Perte de chiffre d’affaires', 'bcpProcedure' => 'Mode dégradé', 'drpProcedure' => 'Bascule site B', 'nextExerciseAt' => '2026-12-01']);
        self::assertResponseStatusCodeSame(201);
        $process = $this->payload();
        self::assertSame(4, $process['rtoHours']);
        $this->client->jsonRequest('POST', '/api/resilience/continuity-processes/'.$process['id'].'/exercises', ['date' => '2026-11-01', 'scenario' => 'Perte du site A', 'participants' => ['DSI', 'Métiers'], 'result' => 'RTO atteint', 'gaps' => ['Communication'], 'improvements' => ['Annuaire de crise']]);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->payload()['exercises']);
    }

    /** @return array<mixed> */
    private function payload(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
