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
        $client->jsonRequest('POST', '/api/v1/integrations', ['type' => 'CONNECTOR', 'provider' => 'JIRA', 'name' => 'Jira actions', 'configuration' => ['baseUrl' => 'https://jira.example.test', 'direction' => 'BIDIRECTIONAL', 'conflictStrategy' => 'MANUAL', 'fieldOwnership' => ['status' => 'JIRA']], 'enabled' => true]);
        self::assertResponseStatusCodeSame(201);
        $connector = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $client->jsonRequest('POST', '/api/decision/connectors/'.$connector['id'].'/reconcile', ['dryRun' => true, 'idempotencyKey' => 'jira-test-1', 'items' => [['id' => 'ABC-1']]]);
        self::assertResponseStatusCodeSame(201);
        $sync = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $client->jsonRequest('POST', '/api/decision/connectors/'.$connector['id'].'/reconcile', ['dryRun' => false, 'idempotencyKey' => 'jira-test-1', 'items' => []]);
        self::assertResponseIsSuccessful();
        self::assertSame($sync['id'], json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['id']);

        $client->jsonRequest('POST', '/api/v1/integrations', [
            'type' => 'DIRECTORY',
            'provider' => 'ACTIVE_DIRECTORY',
            'name' => 'AD préproduction',
            'credential' => 'not-returned-bind-password',
            'configuration' => [
                'host' => 'ldaps://ad.preprod.example.test',
                'port' => 636,
                'baseDn' => 'DC=preprod,DC=example,DC=test',
                'bindDn' => 'CN=riskpilot,OU=Services,DC=preprod,DC=example,DC=test',
                'userFilter' => '(&(objectClass=user)(sAMAccountName={username}))',
                'groupMappings' => ['CN=Risk Managers,OU=Groups,DC=preprod,DC=example,DC=test' => User::ROLE_RISK_MANAGER],
            ],
            'enabled' => false,
        ]);
        self::assertResponseStatusCodeSame(201);
        $directory = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($directory['credentialConfigured']);
        self::assertNull($directory['secret']);
        self::assertStringNotContainsString('not-returned-bind-password', (string) $client->getResponse()->getContent());

        $client->jsonRequest('POST', '/api/v1/integrations', ['type' => 'DIRECTORY', 'provider' => 'ACTIVE_DIRECTORY', 'name' => 'LDAP non chiffré', 'credential' => 'secret', 'configuration' => ['host' => 'ldap://ad.example.test', 'port' => 389]]);
        self::assertResponseStatusCodeSame(422);
        $client->setServerParameter('HTTP_X_RISKPILOT_KEY', '');
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($other));
        $client->jsonRequest('PUT', '/api/v1/integrations/'.$created['id'], ['name' => 'Vol']);
        self::assertResponseStatusCodeSame(404);
    }
}
