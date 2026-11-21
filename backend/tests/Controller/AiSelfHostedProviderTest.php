<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\AiCallMetrics;
use App\Application\AiCopilotClient;
use App\Application\AiEndpointPolicy;
use App\Entity\AiSettings;
use App\Entity\Organization;
use App\Entity\User;
use App\Security\SecretCipher;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AiSelfHostedProviderTest extends WebTestCase
{
    private const ENDPOINT = 'http://ollama:11434/v1';

    public function testAdministratorsCanOnlyChooseEndpointsDeclaredByTheServerOperator(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $container = self::getContainer();
        $container->set(AiEndpointPolicy::class, new AiEndpointPolicy([self::ENDPOINT.'/', 'ftp://bad.example.test', 'http://user:pw@creds.example.test/v1', 'not a url', 42]));
        $manager = $container->get(EntityManagerInterface::class);
        $tool = new SchemaTool($manager);
        $tool->dropSchema($manager->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($manager->getMetadataFactory()->getAllMetadata());
        $organization = new Organization('Primary');
        $admin = new User('admin@example.test', 'Alice', 'Admin', $organization, [User::ROLE_ADMIN]);
        $manager->persist($organization);
        $manager->persist($admin);
        $manager->flush();
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$container->get(JWTTokenManagerInterface::class)->create($admin));

        $client->request('GET', '/api/settings/ai');
        self::assertSame([self::ENDPOINT], json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['selfHostedEndpoints'], 'Only valid, credential-free http(s) endpoints are exposed.');

        $valid = ['provider' => 'SELF_HOSTED', 'baseUrl' => self::ENDPOINT, 'model' => 'llama3.1:8b', 'dataPolicy' => 'CONTEXTUAL', 'systemPrompt' => '', 'enabled' => false];
        foreach ([
            ['baseUrl' => 'http://169.254.169.254/latest'],
            ['baseUrl' => 'http://ollama:11434/v1/../admin'],
            ['baseUrl' => 'https://api.openai.com/v1'],
            ['baseUrl' => ''],
            ['model' => 'bad model!'],
            ['model' => ''],
            ['model' => '../../etc'],
        ] as $invalid) {
            $client->jsonRequest('PUT', '/api/settings/ai', $invalid + $valid);
            self::assertResponseStatusCodeSame(422, json_encode($invalid, JSON_THROW_ON_ERROR));
        }

        // A key saved for a hosted provider is never carried over to a self-hosted endpoint.
        $client->jsonRequest('PUT', '/api/settings/ai', ['provider' => 'OPENAI', 'baseUrl' => 'https://api.openai.com/v1', 'model' => 'gpt-5-mini', 'apiKey' => 'sk-hosted-secret', 'dataPolicy' => 'MINIMAL', 'systemPrompt' => '', 'enabled' => false]);
        self::assertResponseIsSuccessful();
        $client->jsonRequest('PUT', '/api/settings/ai', $valid);
        self::assertResponseIsSuccessful();
        $saved = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('SELF_HOSTED', $saved['provider']);
        self::assertSame(self::ENDPOINT, $saved['baseUrl']);
        self::assertFalse($saved['apiKeyConfigured'], 'The hosted provider key was dropped.');
        self::assertNull($manager->getRepository(AiSettings::class)->findOneBy([])->getEncryptedApiKey());

        $client->jsonRequest('PUT', '/api/settings/ai', [...$valid, 'enabled' => true]);
        self::assertResponseStatusCodeSame(422, 'Enabling still needs the terms acknowledgement.');
        $client->jsonRequest('PUT', '/api/settings/ai', [...$valid, 'enabled' => true, 'acknowledgeTerms' => true]);
        self::assertResponseIsSuccessful();
        self::assertTrue(json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['enabled'], 'No API key is required for a self-hosted model.');

        // Going back to a hosted provider requires a new key.
        $client->jsonRequest('PUT', '/api/settings/ai', ['provider' => 'MISTRAL', 'model' => 'mistral-large-latest', 'dataPolicy' => 'MINIMAL', 'systemPrompt' => '', 'enabled' => false]);
        self::assertResponseIsSuccessful();
        $client->jsonRequest('PUT', '/api/settings/ai', [...$valid, 'apiKey' => 'local-token']);
        self::assertResponseIsSuccessful();
        self::assertTrue(json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['apiKeyConfigured'], 'An optional key (reverse proxy token) is still accepted.');
    }

    public function testRequestsUseChatCompletionsWithoutAuthorizationWhenNoKeyIsSetAndRefuseUnlistedEndpoints(): void
    {
        $self = $this;
        $seen = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen = ['method' => $method, 'url' => $url, 'headers' => array_map('strtolower', $options['headers']), 'body' => json_decode((string) $options['body'], true)];

            return new MockResponse(json_encode(['model' => 'llama3.1:8b', 'choices' => [['message' => ['content' => 'OK']]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2]], JSON_THROW_ON_ERROR));
        });
        $cipher = new SecretCipher('test-secret-at-least-32-characters-long');
        $settings = new AiSettings(new Organization('Org'));
        $settings->configure('SELF_HOSTED', self::ENDPOINT, 'llama3.1:8b', 'MINIMAL', '', true);

        $client = new AiCopilotClient($http, $cipher, 0, new AiEndpointPolicy([self::ENDPOINT]));
        $metrics = new AiCallMetrics();
        self::assertSame('OK', $client->askGlobal($settings, 'Bonjour', [], 'fr', 'safety', [], $metrics));
        self::assertSame(self::ENDPOINT.'/chat/completions', $seen['url']);
        self::assertSame('llama3.1:8b', $seen['body']['model']);
        self::assertSame([], array_filter($seen['headers'], static fn (string $header): bool => str_starts_with($header, 'authorization')), 'No credential is sent when none is configured.');
        self::assertSame(10, $metrics->inputTokens);

        $settings->setEncryptedApiKey($cipher->encrypt('local-token'));
        $client->askGlobal($settings, 'Bonjour', [], 'fr', 'safety', [], new AiCallMetrics());
        self::assertContains('authorization: bearer local-token', $seen['headers']);

        $seen = [];
        $this->expectException(\RuntimeException::class);
        try {
            (new AiCopilotClient($http, $cipher, 0, new AiEndpointPolicy([])))->askGlobal($settings, 'Bonjour', [], 'fr', 'safety', [], new AiCallMetrics());
        } finally {
            $self::assertSame([], $seen, 'No request leaves the server when the endpoint is no longer allowed.');
        }
    }
}
