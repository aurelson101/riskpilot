<?php

declare(strict_types=1);

namespace App\Security;

final readonly class SecretCipher
{
    private string $key;

    public function __construct(string $appSecret)
    {
        $this->key = sodium_crypto_generichash($appSecret, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function encrypt(string $value): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce.sodium_crypto_secretbox($value, $nonce, $this->key));
    }

    public function decrypt(string $value): string
    {
        $decoded = base64_decode($value, true);
        if (false === $decoded || strlen($decoded) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Secret chiffré invalide.');
        }
        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key);
        if (false === $plain) {
            throw new \RuntimeException('Impossible de déchiffrer le secret.');
        }

        return $plain;
    }
}
