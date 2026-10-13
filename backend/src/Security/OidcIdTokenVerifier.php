<?php

declare(strict_types=1);

namespace App\Security;

use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Token\Plain;

final class OidcIdTokenVerifier
{
    /** @param array<string, mixed> $jwks @return array<string, mixed> */
    public function verify(string $raw, array $jwks, string $issuer, string $clientId, string $nonce): array
    {
        if (strlen($raw) > 16384) {
            throw new \RuntimeException('Jeton OIDC invalide.');
        }
        $token = (new Parser(new JoseEncoder()))->parse($raw);
        if (!$token instanceof Plain || 'RS256' !== $token->headers()->get('alg') || !is_string($token->headers()->get('kid'))) {
            throw new \RuntimeException('Signature OIDC non prise en charge.');
        }
        $keys = array_values(array_filter($jwks['keys'] ?? [], static fn ($key): bool => is_array($key)
            && ($key['kid'] ?? null) === $token->headers()->get('kid') && 'RSA' === ($key['kty'] ?? null)
            && 'sig' === ($key['use'] ?? 'sig') && 'RS256' === ($key['alg'] ?? 'RS256')
            && in_array('verify', $key['key_ops'] ?? ['verify'], true)));
        if (1 !== count($keys) || !(new Sha256())->verify($token->signature()->hash(), $token->payload(), InMemory::plainText($this->publicKey($keys[0])))) {
            throw new \RuntimeException('Signature OIDC invalide.');
        }
        $claims = $token->claims()->all();
        $now = time();
        foreach (['exp', 'iat'] as $claim) {
            if (!($claims[$claim] ?? null) instanceof \DateTimeImmutable) {
                throw new \RuntimeException('Validité temporelle OIDC manquante.');
            }
        }
        $audience = $claims['aud'] ?? [];
        if ($issuer !== ($claims['iss'] ?? null) || !is_array($audience) || !in_array($clientId, $audience, true)
            || (isset($claims['azp']) && $clientId !== $claims['azp']) || (count($audience) > 1 && ($claims['azp'] ?? null) !== $clientId)
            || !is_string($claims['nonce'] ?? null) || !hash_equals($nonce, $claims['nonce'])
            || !is_string($claims['sub'] ?? null) || '' === $claims['sub'] || strlen($claims['sub']) > 255
            || $claims['exp']->getTimestamp() <= $now || $claims['iat']->getTimestamp() < $now - 600 || $claims['iat']->getTimestamp() > $now + 60
            || (isset($claims['nbf']) && (!$claims['nbf'] instanceof \DateTimeImmutable || $claims['nbf']->getTimestamp() > $now + 60))) {
            throw new \RuntimeException('Identité OIDC invalide ou expirée.');
        }

        return $claims;
    }

    /** @param array<string, mixed> $jwk */
    private function publicKey(array $jwk): string
    {
        $integer = static function (mixed $value): string {
            if (!is_string($value) || !preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
                throw new \RuntimeException('Clé OIDC invalide.');
            }
            $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);
            if (false === $decoded || '' === $decoded) {
                throw new \RuntimeException('Clé OIDC invalide.');
            }
            $decoded = ltrim($decoded, "\0");

            return '' !== $decoded && ord($decoded[0]) > 127 ? "\0".$decoded : $decoded;
        };
        $n = $integer($jwk['n'] ?? null);
        $e = $integer($jwk['e'] ?? null);
        if (strlen($n) < 256 || strlen($n) > 1025 || strlen($e) > 8) {
            throw new \RuntimeException('Taille de clé OIDC invalide.');
        }
        $der = $this->der(0x30, $this->der(0x02, $n).$this->der(0x02, $e));
        $pem = "-----BEGIN RSA PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END RSA PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        $details = false === $key ? false : openssl_pkey_get_details($key);
        if (false === $details || $details['bits'] < 2048) {
            throw new \RuntimeException('Clé RSA OIDC invalide.');
        }

        return $pem;
    }

    private function der(int $tag, string $value): string
    {
        $size = strlen($value);
        $length = $size < 128 ? chr($size) : "\x82".pack('n', $size);

        return chr($tag).$length.$value;
    }
}
