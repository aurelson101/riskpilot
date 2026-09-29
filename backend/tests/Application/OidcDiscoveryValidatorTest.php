<?php

declare(strict_types=1);

namespace App\Tests\Application;

use App\Application\OidcDiscoveryValidator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class OidcDiscoveryValidatorTest extends TestCase
{
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
