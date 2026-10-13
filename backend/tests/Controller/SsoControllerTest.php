<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Application\OidcClient;
use App\Application\OidcDiscoveryValidator;
use App\Entity\Organization;
use App\Entity\User;
use App\Security\OidcIdTokenVerifier;
use App\Security\SecretCipher;
use App\Tests\Security\OidcTestTokens;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SsoControllerTest extends WebTestCase
{
    public function testFullCodeFlowMfaAndReplayProtection(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $container = self::getContainer();
        foreach (['127.0.0.1', '192.0.2.1', '192.0.2.2', '192.0.2.3', '192.0.2.4', '192.0.2.5', '192.0.2.6', '192.0.2.90'] as $address) {
            foreach (['sso-start|', 'sso-complete|'] as $prefix) {
                $container->get('limiter.login')->create($prefix.$address)->reset();
            }
        }
        $manager = $container->get(EntityManagerInterface::class);
        $metadata = $manager->getMetadataFactory()->getAllMetadata();
        $tool = new SchemaTool($manager);
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $organization = new Organization('SSO tenant');
        $admin = new User('admin@example.test', 'Alice', 'Admin', $organization, [User::ROLE_ADMIN]);
        $otherOrganization = new Organization('Other tenant');
        $other = new User('other@example.test', 'Other', 'User', $otherOrganization, [User::ROLE_VIEWER]);
        $cipher = $container->get(SecretCipher::class);
        $admin->enableMfa($cipher->encrypt('JBSWY3DPEHPK3PXP'), [password_hash('SSO-TEST-RECOVERY', PASSWORD_DEFAULT)]);
        foreach ([$organization, $otherOrganization, $admin, $other] as $entity) {
            $manager->persist($entity);
        }
        $manager->flush();
        $nonce = '';
        $tokens = new OidcTestTokens();
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$nonce, $tokens): MockResponse {
            self::assertSame(0, $options['max_redirects']);
            if (str_contains($url, '.well-known')) {
                return new MockResponse(json_encode([
                    'issuer' => 'https://accounts.google.com', 'authorization_endpoint' => 'https://accounts.google.com/authorize',
                    'token_endpoint' => 'https://oauth2.googleapis.com/token', 'jwks_uri' => 'https://www.googleapis.com/keys',
                    'response_types_supported' => ['code'], 'scopes_supported' => ['openid'],
                    'id_token_signing_alg_values_supported' => ['RS256'], 'token_endpoint_auth_methods_supported' => ['client_secret_post'],
                ], JSON_THROW_ON_ERROR));
            }
            if ('POST' === $method) {
                parse_str($options['body'], $body);
                self::assertSame('authorization_code', $body['grant_type']);
                self::assertSame(64, strlen($body['code_verifier']));

                return new MockResponse(json_encode(['id_token' => $tokens->token(['nonce' => $nonce])], JSON_THROW_ON_ERROR));
            }

            return new MockResponse(json_encode($tokens->jwks, JSON_THROW_ON_ERROR));
        });
        $discovery = new OidcDiscoveryValidator($http);
        $container->set(OidcDiscoveryValidator::class, $discovery);
        $container->set(OidcClient::class, new OidcClient($http, $discovery, new OidcIdTokenVerifier(), $cipher));
        $client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$container->get(JWTTokenManagerInterface::class)->create($admin));
        $configuration = ['issuer' => 'https://accounts.google.com', 'clientId' => 'test-client', 'accountMappings' => [['userId' => $other->getId(), 'subject' => 'stable-user']]];
        $client->jsonRequest('POST', '/api/v1/integrations', ['type' => 'OIDC', 'provider' => 'GOOGLE_WORKSPACE', 'name' => 'Google', 'configuration' => $configuration, 'credential' => 'test-only-secret', 'enabled' => true]);
        self::assertResponseStatusCodeSame(422);
        $configuration['accountMappings'][0]['userId'] = $admin->getId();
        $client->jsonRequest('POST', '/api/v1/integrations', ['type' => 'OIDC', 'provider' => 'GOOGLE_WORKSPACE', 'name' => 'Google', 'configuration' => $configuration, 'credential' => 'test-only-secret', 'enabled' => true]);
        self::assertResponseStatusCodeSame(201);
        self::assertStringNotContainsString('test-only-secret', $client->getResponse()->getContent());
        $id = json_decode($client->getResponse()->getContent(), true)['id'];
        $client->setServerParameter('HTTP_AUTHORIZATION', '');
        $client->request('GET', '/api/auth/sso/providers');
        self::assertResponseIsSuccessful();
        self::assertCount(1, json_decode($client->getResponse()->getContent(), true)['items']);
        $client->request('GET', '/api/auth/sso/'.$id.'/start');
        self::assertResponseStatusCodeSame(302);
        parse_str(parse_url($client->getResponse()->headers->get('Location'), PHP_URL_QUERY), $parameters);
        self::assertSame('S256', $parameters['code_challenge_method']);
        $nonce = $parameters['nonce'];
        $client->request('GET', '/api/auth/sso/callback?state='.$parameters['state'].'&code=test-code');
        self::assertResponseRedirects('http://localhost:8080/login?sso=complete');
        $client->jsonRequest('POST', '/api/auth/sso/complete', []);
        self::assertResponseStatusCodeSame(202);
        self::assertArrayNotHasKey('token', json_decode($client->getResponse()->getContent(), true));
        $client->jsonRequest('POST', '/api/auth/sso/complete', ['mfaCode' => 'invalid']);
        self::assertResponseStatusCodeSame(401);
        $client->jsonRequest('POST', '/api/auth/sso/complete', ['mfaCode' => 'SSO-TEST-RECOVERY']);
        self::assertResponseIsSuccessful();
        self::assertNotEmpty(json_decode($client->getResponse()->getContent(), true)['token']);
        $client->jsonRequest('POST', '/api/auth/sso/complete', []);
        self::assertResponseStatusCodeSame(401);
        $client->request('GET', '/api/auth/sso/callback?state='.$parameters['state'].'&code=test-code');
        self::assertResponseRedirects('http://localhost:8080/login?sso=error');
        $client->request('GET', '/api/auth/sso/callback?state=forged&code=forged');
        self::assertResponseRedirects('http://localhost:8080/login?sso=error');

        $client->request('GET', '/api/auth/sso/'.$id.'/start');
        parse_str(parse_url($client->getResponse()->headers->get('Location'), PHP_URL_QUERY), $parameters);
        $nonce = $parameters['nonce'];
        $client->request('GET', '/api/auth/sso/callback?state='.$parameters['state'].'&code=test-code');
        self::assertResponseRedirects('http://localhost:8080/login?sso=complete');
        $client->setServerParameter('HTTP_ORIGIN', 'https://attacker.example.test');
        $client->jsonRequest('POST', '/api/auth/sso/complete', []);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('SSO_ORIGIN_REJECTED', json_decode($client->getResponse()->getContent(), true)['code']);
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control'));
        $client->setServerParameter('HTTP_ORIGIN', 'http://localhost:8080');
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            // Changing IP must not reset the per-ticket failure budget.
            $client->setServerParameter('REMOTE_ADDR', '192.0.2.'.$attempt);
            $client->jsonRequest('POST', '/api/auth/sso/complete', ['mfaCode' => 'invalid']);
            self::assertResponseStatusCodeSame(401);
            $result = json_decode($client->getResponse()->getContent(), true);
            self::assertSame($attempt < 5 ? 'INVALID_MFA_CODE' : 'SSO_MFA_ATTEMPTS_EXCEEDED', $result['code']);
            self::assertArrayNotHasKey('token', $result);
        }
        $client->setServerParameter('REMOTE_ADDR', '192.0.2.6');
        self::assertTrue($container->get(EntityManagerInterface::class)->find(User::class, $admin->getId())->isTemporarilyLocked());
        $client->jsonRequest('POST', '/api/auth/sso/complete', ['mfaCode' => 'SSO-TEST-RECOVERY']);
        self::assertResponseStatusCodeSame(401);
        self::assertSame('SSO_SESSION_INVALID', json_decode($client->getResponse()->getContent(), true)['code']);

        $client->request('GET', '/api/auth/sso/'.$id.'/start');
        parse_str(parse_url($client->getResponse()->headers->get('Location'), PHP_URL_QUERY), $parameters);
        $client->request('GET', '/api/auth/sso/callback?state='.$parameters['state'].'&error=access_denied');
        self::assertResponseRedirects('http://localhost:8080/login?sso=cancelled');

        $client->setServerParameter('REMOTE_ADDR', '192.0.2.90');
        for ($attempt = 1; $attempt <= 6; ++$attempt) {
            $client->jsonRequest('POST', '/api/auth/sso/complete', []);
        }
        self::assertResponseStatusCodeSame(429);
        self::assertSame('TOO_MANY_SSO_ATTEMPTS', json_decode($client->getResponse()->getContent(), true)['code']);
        self::assertGreaterThan(0, (int) $client->getResponse()->headers->get('Retry-After'));
        self::assertStringContainsString('no-store', $client->getResponse()->headers->get('Cache-Control'));
    }
}
