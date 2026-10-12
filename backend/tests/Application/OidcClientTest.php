<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\OidcClient;
use App\Application\OidcDiscoveryValidator;
use App\Entity\Organization;
use App\Entity\PlatformIntegration;
use App\Security\OidcIdTokenVerifier;
use App\Security\SecretCipher;
use App\Tests\Security\OidcTestTokens;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OidcClientTest extends TestCase
{
    #[DataProvider('providers')]
    public function testProviderIdentityWithPkceAndIssuer(string $provider, string $issuer, string $authMethod): void
    {
        $tokens = new OidcTestTokens();
        $origin = 'https://'.parse_url($issuer, PHP_URL_HOST);
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($issuer, $origin, $authMethod, $tokens): MockResponse {
            if (str_contains($url, '.well-known')) {
                return new MockResponse(json_encode([
                    'issuer' => $issuer, 'authorization_endpoint' => $origin.'/authorize', 'token_endpoint' => $origin.'/token', 'jwks_uri' => $origin.'/keys',
                    'response_types_supported' => ['code'], 'scopes_supported' => ['openid'], 'id_token_signing_alg_values_supported' => ['RS256'],
                    'token_endpoint_auth_methods_supported' => [$authMethod],
                ], JSON_THROW_ON_ERROR));
            }
            if ('POST' === $method) {
                parse_str($options['body'], $body);
                self::assertSame('test-verifier', $body['code_verifier']);
                if ('client_secret_post' === $authMethod) {
                    self::assertSame('test-client-secret', $body['client_secret']);
                } else {
                    self::assertArrayNotHasKey('client_secret', $body);
                }

                return new MockResponse(json_encode(['id_token' => $tokens->token(['iss' => $issuer, 'hd' => 'example.test', 'tid' => explode('/', trim(parse_url($issuer, PHP_URL_PATH) ?? '', '/'))[0], 'oid' => '11111111-1111-1111-1111-111111111111'])], JSON_THROW_ON_ERROR));
            }

            return new MockResponse(json_encode($tokens->jwks, JSON_THROW_ON_ERROR));
        });
        $cipher = new SecretCipher('test-only-secret');
        $configuration = ['issuer' => $issuer, 'clientId' => 'test-client', 'accountMappings' => []];
        if ('GOOGLE_WORKSPACE' === $provider) {
            $configuration['hostedDomain'] = 'example.test';
        }
        $item = new PlatformIntegration(new Organization('Test'), 'OIDC', $provider, 'SSO', $configuration);
        $item->setEncryptedCredential($cipher->encrypt('test-client-secret'));
        $client = new OidcClient($http, new OidcDiscoveryValidator($http, [$issuer]), new OidcIdTokenVerifier(), $cipher);
        $flow = ['nonce' => 'test-nonce', 'verifier' => 'test-verifier'];
        $url = $client->authorizationUrl($item, 'https://riskpilot.test/api/auth/sso/callback', 'test-state', $flow);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('S256', $query['code_challenge_method']);
        self::assertSame('test-state', $query['state']);
        self::assertSame('test-nonce', $query['nonce']);
        self::assertSame('MICROSOFT_ENTRA' === $provider ? '11111111-1111-1111-1111-111111111111' : 'stable-user', $client->identity($item, 'https://riskpilot.test/api/auth/sso/callback', 'test-code', $flow));
    }

    public static function providers(): iterable
    {
        yield ['GOOGLE_WORKSPACE', 'https://accounts.google.com', 'client_secret_post'];
        yield ['MICROSOFT_ENTRA', 'https://login.microsoftonline.com/22222222-2222-2222-2222-222222222222/v2.0', 'client_secret_post'];
        yield ['KEYCLOAK', 'https://sso.example.test/realms/riskpilot', 'client_secret_basic'];
        yield ['AUTHENTIK', 'https://sso.example.test/application/o/riskpilot/', 'client_secret_basic'];
        yield ['GENERIC', 'https://sso.example.test', 'client_secret_basic'];
    }
}
