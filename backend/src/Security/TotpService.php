<?php

declare(strict_types=1);

namespace App\Security;

final class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    public function provisioningUri(string $secret, string $email, string $issuer = 'RiskPilot'): string
    {
        $label = rawurlencode($issuer.':'.$email);

        return sprintf('otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30', $label, $secret, rawurlencode($issuer));
    }

    public function verify(string $secret, string $code, ?int $now = null): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (6 !== strlen($code)) {
            return false;
        }
        $counter = intdiv($now ?? time(), 30);
        for ($offset = -1; $offset <= 1; ++$offset) {
            if (hash_equals($this->code($secret, $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function generateRecoveryCodes(): array
    {
        return array_map(static fn (): string => strtoupper(substr(bin2hex(random_bytes(6)), 0, 4).'-'.substr(bin2hex(random_bytes(6)), 0, 8)), range(1, 8));
    }

    private function code(string $secret, int $counter): string
    {
        $binary = $this->base32Decode($secret);
        $data = pack('N2', intdiv($counter, 4294967296), $counter % 4294967296);
        $hash = hash_hmac('sha1', $data, $binary, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24) | ((ord($hash[$offset + 1]) & 0xFF) << 16) | ((ord($hash[$offset + 2]) & 0xFF) << 8) | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $value): string
    {
        $bits = '';
        foreach (str_split($value) as $character) {
            $bits .= str_pad(decbin(ord($character)), 8, '0', STR_PAD_LEFT);
        }
        $output = '';
        foreach (str_split($bits, 5) as $chunk) {
            $output .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $output;
    }

    private function base32Decode(string $value): string
    {
        $bits = '';
        foreach (str_split(strtoupper($value)) as $character) {
            $position = strpos(self::ALPHABET, $character);
            if (false === $position) {
                throw new \InvalidArgumentException('Secret TOTP invalide.');
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }
        $output = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (8 === strlen($chunk)) {
                $output .= chr(bindec($chunk));
            }
        }

        return $output;
    }
}
