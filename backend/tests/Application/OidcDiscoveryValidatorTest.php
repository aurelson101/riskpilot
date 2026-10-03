<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\OidcDiscoveryValidator;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OidcDiscoveryValidatorTest extends TestCase
{
    #[DataProvider('configuredProviders')]
    public function testConfiguredOpenSourceIssuers(string $provider, string $issuer): void
    {
        $metadata = $this->metadata($issuer);
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use ($issuer, $metadata): MockResponse {
            self::assertSame('GET', $method);
            self::assertSame(rtrim($issuer, '/').'/.well-known/openid-configuration', $url);
            self::assertSame(0, $options['max_redirects']);
            self::assertSame(10.0, (float) $options['max_duration']);
            self::assertTrue($options['verify_peer']);
            return new MockResponse(json_encode($metadata, JSON_THROW_ON_ERROR));
        });
        $result = (new OidcDiscoveryValidator($http, [$issuer]))->validate($provider, $issuer);
        self::assertTrue($result['validated']);
        self::assertSame($provider, $result['provider']);
    }

    public static function configuredProviders(): iterable
    {
        yield ['KEYCLOAK', 'https://sso.example.test/realms/riskpilot'];
        yield ['KEYCLOAK', 'https://sso.example.test/auth/realms/riskpilot'];
        yield ['AUTHENTIK', 'https://sso.example.test/application/o/riskpilot/'];
    }

    public function testCustomIssuerIsDeniedBeforeAnyNetworkRequest(): void
    {
        $http = new MockHttpClient(static function (): MockResponse { self::fail('Unapproved network request.'); });
        $this->expectException(\RuntimeException::class);
        (new OidcDiscoveryValidator($http))->validate('KEYCLOAK', 'https://sso.example.test/realms/riskpilot');
    }

    #[DataProvider('invalidMetadata')]
    public function testMalformedMetadataIsRejected(array $override): void
    {
        $metadata = [...$this->metadata('https://accounts.google.com'), ...$override];
        $this->expectException(\RuntimeException::class);
        (new OidcDiscoveryValidator(new MockHttpClient(new MockResponse(json_encode($metadata, JSON_THROW_ON_ERROR)))))->validate('GOOGLE_WORKSPACE', 'https://accounts.google.com');
    }

    public static function invalidMetadata(): iterable
    {
        yield [['issuer' => ['unexpected']]];
        yield [['token_endpoint' => ['unexpected']]];
        yield [['token_endpoint' => 'https://user@accounts.google.com/token']];
        yield [['token_endpoint' => 'https://accounts.google.com/token#fragment']];
        yield [['response_types_supported' => 'code']];
        yield [['scopes_supported' => [['openid']]]];
        yield [['id_token_signing_alg_values_supported' => ['none']]];
        yield [['code_challenge_methods_supported' => [42]]];
    }

    public function testEntraTenantDiscovery(): void
    {
        $issuer = 'https://login.microsoftonline.com/11111111-1111-1111-1111-111111111111/v2.0';
        self::assertTrue((new OidcDiscoveryValidator(new MockHttpClient(new MockResponse(json_encode($this->metadata($issuer), JSON_THROW_ON_ERROR)))))->validate('MICROSOFT_ENTRA', $issuer)['validated']);
    }

    private function metadata(string $issuer): array
    {
        return ['issuer' => $issuer, 'authorization_endpoint' => 'https://sso.example.test/authorize', 'token_endpoint' => 'https://sso.example.test/token', 'jwks_uri' => 'https://sso.example.test/jwks', 'response_types_supported' => ['code'], 'scopes_supported' => ['openid'], 'id_token_signing_alg_values_supported' => ['RS256'], 'code_challenge_methods_supported' => ['S256']];
    }

    public function testItValidatesGoogleAuthorizationCodeDiscovery(): void
    {
        $response = new MockResponse(json_encode([
            'issuer' => 'https://accounts.google.com',
            'authorization_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_endpoint' => 'https://oauth2.googleapis.com/token',
            'jwks_uri' => 'https://www.googleapis.com/oauth2/v3/certs',
            'response_types_supported' => ['code'],
            'scopes_supported' => ['openid', 'email'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'code_challenge_methods_supported' => ['S256'],
        ], JSON_THROW_ON_ERROR));
        $validator = new OidcDiscoveryValidator(new MockHttpClient($response));

        $result = $validator->validate('GOOGLE_WORKSPACE', 'https://accounts.google.com');

        self::assertTrue($result['validated']);
        self::assertTrue($result['authorizationCode']);
        self::assertTrue($result['pkceS256']);
        self::assertSame(['RS256'], $result['signingAlgorithms']);
    }

    public function testItRejectsAnIssuerMismatch(): void
    {
        $response = new MockResponse(json_encode(['issuer' => 'https://attacker.example'], JSON_THROW_ON_ERROR));
        $validator = new OidcDiscoveryValidator(new MockHttpClient($response));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ne correspond pas');
        $validator->validate('GOOGLE_WORKSPACE', 'https://accounts.google.com');
    }

    public function testItRejectsArbitraryDiscoveryHosts(): void
    {
        $validator = new OidcDiscoveryValidator(new MockHttpClient());

        $this->expectException(\RuntimeException::class);
        $validator->validate('GENERIC', 'https://127.0.0.1');
    }
}
