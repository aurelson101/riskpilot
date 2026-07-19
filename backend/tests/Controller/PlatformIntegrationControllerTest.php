<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Organization;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PlatformIntegrationControllerTest extends WebTestCase
{
    public function testServiceSecretIsShownOnceAndTenantIdorIsBlocked(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $metadata = $manager->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($manager);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $first = new Organization('Premier');
        $second = new Organization('Second');
        $admin = new User('admin@example.test', 'Alice', 'Admin', $first, [User::ROLE_ADMIN]);
        $other = new User('other@example.test', 'Bob', 'Admin', $second, [User::ROLE_ADMIN]);
        foreach ([$first, $second, $admin, $other] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($admin));
        $client->jsonRequest('POST', '/api/v1/integrations', ['type' => 'API_KEY', 'provider' => 'GENERIC', 'name' => 'SIEM', 'configuration' => ['scopes' => ['events:write']], 'enabled' => true]);
        self::assertResponseStatusCodeSame(201);
        $created = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringStartsWith('rp_api_key_', $created['secret']);
        $client->setServerParameter('HTTP_X_RISKPILOT_KEY', $created['secret']);
        $client->request('GET', '/api/v1/service/status');
        self::assertResponseIsSuccessful();
        $service = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($first->getId(), $service['organizationId']);
        $client->setServerParameter('HTTP_X_RISKPILOT_KEY', '');
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($other));
        $client->jsonRequest('PUT', '/api/v1/integrations/'.$created['id'], ['name' => 'Vol']);
        self::assertResponseStatusCodeSame(404);
    }
}
