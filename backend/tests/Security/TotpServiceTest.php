<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\SecretCipher;
use App\Security\TotpService;
use PHPUnit\Framework\TestCase;

final class TotpServiceTest extends TestCase
{
    public function testValidatesRfc6238CodeAndRejectsInvalidCode(): void
    {
        $service = new TotpService();
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

        self::assertTrue($service->verify($secret, '287082', 59));
        self::assertFalse($service->verify($secret, '000000', 59));
    }

    public function testSecretCipherRoundTripDoesNotExposePlainText(): void
    {
        $cipher = new SecretCipher('test-secret-that-is-not-committed-in-production');
        $encrypted = $cipher->encrypt('smtp-password');

        self::assertNotSame('smtp-password', $encrypted);
        self::assertSame('smtp-password', $cipher->decrypt($encrypted));
    }
}
