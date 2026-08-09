<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Organization;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OperationalWorkspaceControllerTest extends WebTestCase
{
    public function testTaskAssignmentAclAndTenantIsolation(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $schema = new SchemaTool($manager);
        $metadata = $manager->getMetadataFactory()->getAllMetadata();
        $schema->dropSchema($metadata);
        $schema->createSchema($metadata);

        $organization = new Organization('Primary');
        $foreignOrganization = new Organization('Foreign');
        $riskManager = new User('manager@example.test', 'Risk', 'Manager', $organization, [User::ROLE_RISK_MANAGER]);
        $viewer = new User('viewer@example.test', 'Task', 'Owner', $organization, [User::ROLE_VIEWER]);
        $foreignViewer = new User('foreign@example.test', 'Foreign', 'Viewer', $foreignOrganization, [User::ROLE_VIEWER]);
        foreach ([$organization, $foreignOrganization, $riskManager, $viewer, $foreignViewer] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($riskManager));
        $client->jsonRequest('POST', '/api/operations/records', [
            'type' => 'TASK',
            'title' => 'Review evidence',
            'status' => 'ACTIVE',
            'ownerId' => $viewer->getId(),
            'dueAt' => '2026-12-31',
            'details' => ['framework' => 'ISO 27001'],
        ]);
        self::assertResponseStatusCodeSame(201);
        $task = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $client->jsonRequest('PUT', '/api/operations/records/'.$task['id'], [
            'title' => 'Review updated evidence',
            'ownerId' => $viewer->getId(),
            'dueAt' => '2027-01-15T10:30',
            'details' => ['framework' => 'ISO 27001', 'reviewed' => true],
        ]);
        self::assertResponseIsSuccessful();
        $updatedTask = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Review updated evidence', $updatedTask['title']);
        self::assertSame('ACTIVE', $updatedTask['status']);
        self::assertSame($viewer->getId(), $updatedTask['owner']['id']);
        self::assertTrue($updatedTask['details']['reviewed']);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($viewer));
        $client->request('GET', '/api/operations/my-tasks');
        self::assertResponseIsSuccessful();
        $tasks = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $tasks['total']);
        self::assertSame('/operations', $tasks['items'][0]['link']);
        self::assertSame('OPERATIONAL', $tasks['items'][0]['source']);

        $client->jsonRequest('POST', '/api/operations/records', ['type' => 'TASK', 'title' => 'Forbidden']);
        self::assertResponseStatusCodeSame(403);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($foreignViewer));
        $client->request('GET', '/api/operations/records?type=TASK');
        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR));
    }
}
