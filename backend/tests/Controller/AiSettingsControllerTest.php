<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AiSettings;
use App\Entity\Organization;
use App\Entity\User;
use App\Security\SecretCipher;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AiSettingsControllerTest extends WebTestCase
{
    public function testSettingsAreEncryptedAdminOnlyAndTenantScoped(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($manager);
        $tool->dropSchema($manager->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($manager->getMetadataFactory()->getAllMetadata());
        $first = new Organization('Primary');
        $second = new Organization('Other');
        $admin = new User('admin@example.test', 'Alice', 'Admin', $first, [User::ROLE_ADMIN]);
        $viewer = new User('viewer@example.test', 'Victor', 'Viewer', $first, [User::ROLE_VIEWER]);
        $otherAdmin = new User('other@example.test', 'Other', 'Admin', $second, [User::ROLE_ADMIN]);
        foreach ([$first, $second, $admin, $viewer, $otherAdmin] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($viewer));
        $client->request('GET', '/api/settings/ai');
        self::assertResponseStatusCodeSame(403);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($admin));
        $client->jsonRequest('PUT', '/api/settings/ai', [
            'provider' => 'OPENAI', 'model' => 'gpt-5-mini', 'apiKey' => 'sk-test-not-a-real-key',
            'dataPolicy' => 'MINIMAL', 'systemPrompt' => 'Toujours citer les sources.', 'enabled' => true,
        ]);
        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($payload['apiKeyConfigured']);
        self::assertArrayNotHasKey('apiKey', $payload);
        self::assertSame('https://api.openai.com/v1', $payload['baseUrl']);

        $stored = $manager->getRepository(AiSettings::class)->findOneBy(['organization' => $first]);
        self::assertInstanceOf(AiSettings::class, $stored);
        self::assertNotSame('sk-test-not-a-real-key', $stored->getEncryptedApiKey());
        self::assertSame('sk-test-not-a-real-key', self::getContainer()->get(SecretCipher::class)->decrypt((string) $stored->getEncryptedApiKey()));

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($otherAdmin));
        $client->request('GET', '/api/settings/ai');
        self::assertResponseIsSuccessful();
        self::assertFalse(json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['apiKeyConfigured']);
    }
}
