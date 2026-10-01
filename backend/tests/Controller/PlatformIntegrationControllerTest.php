<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Organization;
use App\Entity\PlatformIntegration;
use App\Entity\SecurityControl;
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
        $firstControl = (new SecurityControl('MFA administration', 'Identity', $first))->setOwner($admin)->setImplementationStatus('IMPLEMENTED')->setEffectiveness(90);
        $secondControl = (new SecurityControl('Control from another tenant', 'Identity', $second))->setOwner($other);
        $manager->persist($firstControl);
        $manager->persist($secondControl);
        $manager->flush();
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($admin));
        $client->jsonRequest('POST', '/api/v1/integrations', ['type' => 'API_KEY', 'provider' => 'GENERIC', 'name' => 'SIEM', 'configuration' => ['scopes' => ['events:write']], 'enabled' => true]);
        self::assertResponseStatusCodeSame(201);
        $created = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringStartsWith('rp_api_key_', $created['secret']);
        self::assertTrue($created['credentialConfigured']);
        self::assertFalse($created['credentialExpired']);
        self::assertNotNull($created['credentialExpiresAt']);
        $client->setServerParameter('HTTP_X_RISKPILOT_KEY', $created['secret']);
        $client->request('GET', '/api/v1/service/status');
        self::assertResponseIsSuccessful();
        $service = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($first->getId(), $service['organizationId']);

        $client->request('GET', '/api/v1/service/controls');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('INSUFFICIENT_SCOPE', json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['code']);

        $client->setServerParameter('HTTP_X_RISKPILOT_KEY', '');
        $client->jsonRequest('POST', '/api/v1/integrations', ['type' => 'API_KEY', 'provider' => 'GENERIC', 'name' => 'Control reader', 'configuration' => ['scopes' => ['controls:read']], 'enabled' => true]);
        self::assertResponseStatusCodeSame(201);
        $reader = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $client->setServerParameter('HTTP_X_RISKPILOT_KEY', $reader['secret']);
        $client->setServerParameter('HTTP_X_REQUEST_ID', 'integration-test-1234');
        $client->request('GET', '/api/v1/service/controls?limit=500&offset=-5&status=IMPLEMENTED');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertSame('integration-test-1234', $client->getResponse()->headers->get('X-Request-ID'));
        self::assertNotNull($client->getResponse()->headers->get('X-RateLimit-Limit'));
        self::assertNotNull($client->getResponse()->headers->get('X-RateLimit-Remaining'));
        $controls = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $controls['items']);
        self::assertSame('MFA administration', $controls['items'][0]['name']);
        self::assertSame(100, $controls['pagination']['limit']);
        self::assertSame(0, $controls['pagination']['offset']);
        self::assertSame(1, $controls['pagination']['total']);
        self::assertNull($controls['pagination']['nextOffset']);
        self::assertSame('IMPLEMENTED', $controls['filters']['status']);

        $client->request('GET', '/api/v1/service/controls?updatedSince=2026-01-01T00:00:00Z');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('INVALID_FILTER', json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['code']);

        $oldReaderSecret = $reader['secret'];
        $client->jsonRequest('POST', '/api/v1/integrations/'.$reader['id'].'/rotate', ['expiresInDays' => 30]);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        $rotated = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotSame($oldReaderSecret, $rotated['secret']);
        self::assertTrue($rotated['credentialConfigured']);
        $client->setServerParameter('HTTP_X_RISKPILOT_KEY', $oldReaderSecret);
        $client->request('GET', '/api/v1/service/status');
        self::assertResponseStatusCodeSame(401);
        $client->setServerParameter('HTTP_X_RISKPILOT_KEY', $rotated['secret']);
        $client->request('GET', '/api/v1/service/status');
        self::assertResponseIsSuccessful();
        $client->jsonRequest('POST', '/api/v1/integrations/'.$reader['id'].'/revoke');
        self::assertResponseIsSuccessful();
        $revoked = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($revoked['credentialConfigured']);
        self::assertFalse($revoked['enabled']);
        $client->request('GET', '/api/v1/service/status');
        self::assertResponseStatusCodeSame(401);

        $expiredSecret = 'rp_api_key_expired-test';
        $managedFirst = $manager->find(Organization::class, $first->getId());
        self::assertInstanceOf(Organization::class, $managedFirst);
        $expired = new PlatformIntegration($managedFirst, 'API_KEY', 'GENERIC', 'Expired', ['scopes' => ['risks:read'], 'expiresAt' => '2025-01-01T00:00:00+00:00'], true);
        $expired->setCredential($expiredSecret);
        $manager->persist($expired);
        $manager->flush();
        $client->setServerParameter('HTTP_X_RISKPILOT_KEY', $expiredSecret);
        $client->request('GET', '/api/v1/service/status');
        self::assertResponseStatusCodeSame(401);
        self::assertSame('EXPIRED_SERVICE_KEY', json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['code']);

        $client->setServerParameter('HTTP_X_RISKPILOT_KEY', 'invalid-key');
        $client->request('GET', '/api/v1/service/actions');
        self::assertResponseStatusCodeSame(401);
        self::assertSame('INVALID_SERVICE_KEY', json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['code']);

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
        $client->jsonRequest('POST', '/api/v1/integrations', ['type' => 'OIDC', 'provider' => 'MICROSOFT_ENTRA', 'name' => 'Entra incomplet', 'configuration' => ['issuer' => 'https://login.microsoftonline.com/organizations/v2.0'], 'enabled' => false]);
        self::assertResponseStatusCodeSame(422);
        $unsupported = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('pas encore raccordée', $unsupported['message']);
        $client->setServerParameter('HTTP_X_RISKPILOT_KEY', '');
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($other));
        $client->jsonRequest('PUT', '/api/v1/integrations/'.$created['id'], ['name' => 'Vol']);
        self::assertResponseStatusCodeSame(404);
    }
}
