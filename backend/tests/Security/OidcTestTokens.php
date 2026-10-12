<?php

declare(strict_types=1);

namespace App\Tests\Security;

final class OidcTestTokens
{
    private \OpenSSLAsymmetricKey $key;
    public array $jwks;

    public function __construct()
    {
        $this->key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $rsa = openssl_pkey_get_details($this->key)['rsa'];
        $this->jwks = ['keys' => [['kid' => 'test', 'kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'n' => self::encode($rsa['n']), 'e' => self::encode($rsa['e'])]]];
    }

    public function token(array $override = [], array $headers = []): string
    {
        $payload = self::encode(json_encode($headers + ['alg' => 'RS256', 'kid' => 'test'], JSON_THROW_ON_ERROR)).'.'.self::encode(json_encode($override + [
            'iss' => 'https://accounts.google.com', 'sub' => 'stable-user', 'aud' => 'test-client',
            'nonce' => 'test-nonce', 'iat' => time(), 'exp' => time() + 300,
        ], JSON_THROW_ON_ERROR));
        openssl_sign($payload, $signature, $this->key, OPENSSL_ALGO_SHA256);

        return $payload.'.'.self::encode($signature);
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
