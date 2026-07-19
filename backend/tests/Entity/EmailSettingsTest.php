<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\EmailSettings;
use App\Entity\Organization;
use PHPUnit\Framework\TestCase;

final class EmailSettingsTest extends TestCase
{
    public function testSwitchingProviderClearsCredentialsThatAreNoLongerUsed(): void
    {
        $settings = new EmailSettings(new Organization('Test'));
        $settings->setEncryptedPassword('encrypted-smtp-password');
        $settings->configure('SMTP2GO', 'mail.smtp2go.com', 587, 'tls', 'smtp-user', 'sender@example.test', 'RiskPilot', null, true);

        $settings->configureOauth('GOOGLE_WORKSPACE', 'google-client', 'encrypted-google-secret', null, 'RiskPilot', null);
        self::assertNull($settings->getEncryptedPassword());
        self::assertSame('', $settings->getUsername());

        $settings->connectOauth('encrypted-access', 'encrypted-refresh', new \DateTimeImmutable('+1 hour'), 'sender@example.test');
        $settings->configure('CUSTOM', 'smtp.example.test', 465, 'ssl', 'custom-user', 'sender@example.test', 'RiskPilot', null, true);
        self::assertNull($settings->getEncryptedAccessToken());
        self::assertNull($settings->getEncryptedRefreshToken());
        self::assertNull($settings->getEncryptedOauthClientSecret());
        self::assertNull($settings->getOauthClientId());
    }
}
