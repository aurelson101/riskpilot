<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Organization;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EvidenceControllerTest extends WebTestCase
{
    public function testVersionedLifecyclePaginationAndTenantIsolation(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($manager);
        $metadata = $manager->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        $organization = new Organization('Evidence tenant');
        $foreignOrganization = new Organization('Foreign tenant');
        $owner = new User('evidence-owner@example.test', 'Evidence', 'Owner', $organization, [User::ROLE_RISK_MANAGER]);
        $approver = new User('evidence-approver@example.test', 'Evidence', 'Approver', $organization, [User::ROLE_ADMIN]);
        $foreign = new User('evidence-foreign@example.test', 'Foreign', 'User', $foreignOrganization, [User::ROLE_RISK_MANAGER]);
        foreach ([$organization, $foreignOrganization, $owner, $approver, $foreign] as $entity) $manager->persist($entity);
        $manager->flush();

        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($owner));
        $payload = ['title' => 'Attestation ISO', 'kind' => 'EXTERNAL_REFERENCE', 'classification' => 'CONFIDENTIAL', 'sourceReference' => 'vault://attestations/iso', 'sha256' => str_repeat('a', 64)];
        $client->jsonRequest('POST', '/api/evidence', $payload);
        self::assertResponseStatusCodeSame(201);
        $created = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $created['version']);
        self::assertSame('DRAFT', $created['status']);

        $client->jsonRequest('POST', '/api/evidence/'.$created['id'].'/submit');
        self::assertResponseIsSuccessful();
        $client->jsonRequest('POST', '/api/evidence/'.$created['id'].'/approve');
        self::assertResponseStatusCodeSame(403);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($approver));
        $client->jsonRequest('POST', '/api/evidence/'.$created['id'].'/approve');
        self::assertResponseIsSuccessful();
        self::assertSame('APPROVED', json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['status']);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($owner));
        $client->jsonRequest('POST', '/api/evidence/'.$created['id'].'/revise', [...$payload, 'title' => 'Attestation ISO renouvelée', 'sha256' => str_repeat('b', 64)]);
        self::assertResponseStatusCodeSame(201);
        $revision = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2, $revision['version']);
        self::assertSame($created['id'], $revision['supersedesId']);

        $client->request('GET', '/api/evidence?page=1&limit=1');
        self::assertResponseIsSuccessful();
        $page = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2, $page['total']);
        self::assertCount(1, $page['items']);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($foreign));
        $client->request('GET', '/api/evidence/'.$revision['id']);
        self::assertResponseStatusCodeSame(404);
        $client->request('GET', '/api/evidence');
        self::assertSame(0, json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['total']);
    }
}
