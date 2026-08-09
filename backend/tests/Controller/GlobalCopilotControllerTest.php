<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AiSettings;
use App\Entity\AuditLog;
use App\Entity\Organization;
use App\Entity\User;
use App\Security\SecretCipher;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GlobalCopilotControllerTest extends WebTestCase
{
    public function testGlobalChatIsConfiguredTenantScopedAuditedAndNeverWritesAutomatically(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        self::getContainer()->set(HttpClientInterface::class, new MockHttpClient(new MockResponse(json_encode(['output_text' => 'Commençons par identifier le périmètre.'], JSON_THROW_ON_ERROR), ['http_code' => 200])));
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($manager);
        $tool->dropSchema($manager->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($manager->getMetadataFactory()->getAllMetadata());

        $organization = new Organization('Primary');
        $otherOrganization = new Organization('Other');
        $managerUser = new User('manager@example.test', 'Risk', 'Manager', $organization, [User::ROLE_RISK_MANAGER]);
        $other = new User('other@example.test', 'Other', 'User', $otherOrganization, [User::ROLE_RISK_MANAGER]);
        foreach ([$organization, $otherOrganization, $managerUser, $other] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($managerUser));

        $client->request('GET', '/api/copilot/context');
        self::assertResponseIsSuccessful();
        $context = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($context['enabled']);
        self::assertContains('RISK_DRAFT', $context['capabilities']);
        self::assertFalse($context['automaticWrite']);
        $client->jsonRequest('POST', '/api/copilot', ['question' => 'Aide-moi à créer un risque tiers', 'consent' => true]);
        self::assertResponseStatusCodeSame(409);

        $managedOrganization = $manager->find(Organization::class, $organization->getId());
        self::assertInstanceOf(Organization::class, $managedOrganization);
        $settings = new AiSettings($managedOrganization);
        $settings->configure('OPENAI', 'https://api.openai.com/v1', 'gpt-test', 'MINIMAL', '', true);
        $settings->setEncryptedApiKey(self::getContainer()->get(SecretCipher::class)->encrypt('provider-secret'));
        $manager->persist($settings);
        $manager->flush();

        $client->jsonRequest('POST', '/api/copilot', ['question' => 'Aide-moi à créer un risque tiers', 'consent' => true]);
        self::assertResponseIsSuccessful();
        $answer = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Commençons par identifier le périmètre.', $answer['answer']);
        self::assertFalse($answer['automaticWrite']);
        $audit = $manager->getRepository(AuditLog::class)->findOneBy([], ['id' => 'DESC']);
        self::assertInstanceOf(AuditLog::class, $audit);
        self::assertSame('[REDACTED]', $audit->getNewValues()['request']['question']);
        self::assertSame('GLOBAL_COPILOT', $audit->getNewValues()['entities'][0]['workflow']);

        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$tokens->create($other));
        $client->request('GET', '/api/copilot/context');
        self::assertResponseIsSuccessful();
        $otherContext = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($otherContext['enabled']);
    }
}
