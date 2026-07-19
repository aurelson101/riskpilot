<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Organization;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ExecutiveGovernanceControllerTest extends WebTestCase
{
    public function testFinancialScenarioValidationAndVision360(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $metadata = $manager->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($manager);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $organization = new Organization('Direction');
        $admin = new User('admin@example.test', 'Alice', 'Admin', $organization, [User::ROLE_ADMIN]);
        $manager->persist($organization);
        $manager->persist($admin);
        $manager->flush();
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($admin));
        $client->jsonRequest('POST', '/api/executive-governance/records', ['type' => 'FINANCIAL_SCENARIO', 'title' => 'Rançongiciel', 'ownerId' => $admin->getId(), 'details' => ['frequencyMin' => 0.1, 'frequencyMax' => 0.3, 'lossMin' => 500000, 'lossMostLikely' => 200000, 'lossMax' => 1000000, 'currency' => 'EUR']]);
        self::assertResponseStatusCodeSame(422);
        $client->jsonRequest('POST', '/api/executive-governance/records', ['type' => 'FINANCIAL_SCENARIO', 'title' => 'Rançongiciel', 'ownerId' => $admin->getId(), 'status' => 'ACTIVE', 'details' => ['frequencyMin' => 0.1, 'frequencyMax' => 0.3, 'lossMin' => 100000, 'lossMostLikely' => 500000, 'lossMax' => 1000000, 'currency' => 'EUR']]);
        self::assertResponseStatusCodeSame(201);
        $client->request('GET', '/api/executive-governance/vision-360');
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $payload['financialScenarios']);
        self::assertSame(0, $payload['risks']['total']);
    }
}
