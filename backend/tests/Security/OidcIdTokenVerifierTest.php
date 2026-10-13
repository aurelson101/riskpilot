<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\OidcIdTokenVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OidcIdTokenVerifierTest extends TestCase
{
    public function testSignedTokenAndStableSubject(): void
    {
        $tokens = new OidcTestTokens();
        $claims = (new OidcIdTokenVerifier())->verify($tokens->token(), $tokens->jwks, 'https://accounts.google.com', 'test-client', 'test-nonce');
        self::assertSame('stable-user', $claims['sub']);
    }

    #[DataProvider('invalidClaims')]
    public function testInvalidClaimsAreDenied(array $claims, array $headers): void
    {
        $tokens = new OidcTestTokens();
        $this->expectException(\Throwable::class);
        (new OidcIdTokenVerifier())->verify($tokens->token($claims, $headers), $tokens->jwks, 'https://accounts.google.com', 'test-client', 'test-nonce');
    }

    public static function invalidClaims(): iterable
    {
        yield [['iss' => 'https://attacker.test'], []];
        yield [['aud' => 'other-client'], []];
        yield [['aud' => ['test-client', 'other-client']], []];
        yield [['azp' => 'other-client'], []];
        yield [['nonce' => 'replayed-nonce'], []];
        yield [['exp' => time() - 60], []];
        yield [['iat' => time() - 601], []];
        yield [['iat' => time() + 300], []];
        yield [['nbf' => time() + 300], []];
        yield [['sub' => ''], []];
        yield [[], ['alg' => 'HS256']];
        yield [[], ['kid' => 'unknown']];
    }

    public function testDifferentSignatureKeyIsDenied(): void
    {
        $first = new OidcTestTokens();
        $second = new OidcTestTokens();
        $this->expectException(\RuntimeException::class);
        (new OidcIdTokenVerifier())->verify($first->token(), $second->jwks, 'https://accounts.google.com', 'test-client', 'test-nonce');
    }
}
