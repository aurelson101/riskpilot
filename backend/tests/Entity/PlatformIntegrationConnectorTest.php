<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Organization;
use App\Entity\PlatformIntegration;
use PHPUnit\Framework\TestCase;

final class PlatformIntegrationConnectorTest extends TestCase
{
    public function testGovernedConnectorConfigurationAndCredential(): void
    {
        $connector = new PlatformIntegration(new Organization('Primary'), 'CONNECTOR', 'JIRA', 'Jira actions', [
            'baseUrl' => 'https://jira.example.test',
            'direction' => 'BIDIRECTIONAL',
            'conflictStrategy' => 'MANUAL',
            'fieldOwnership' => ['status' => 'JIRA', 'risk' => 'RISKPILOT'],
        ], true);
        $connector->setCredential('rp_connector_test-secret');

        self::assertTrue($connector->isEnabled());
        self::assertTrue($connector->verifies('rp_connector_test-secret'));
        self::assertFalse($connector->verifies('wrong'));
    }

    public function testConnectorRejectsUnsafeOrIncompleteConfiguration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PlatformIntegration(new Organization('Primary'), 'CONNECTOR', 'SERVICENOW', 'CMDB', [
            'baseUrl' => 'http://insecure.example.test',
            'direction' => 'IMPORT',
        ]);
    }

    public function testConnectorRejectsAnIncompleteHttpsUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PlatformIntegration(new Organization('Primary'), 'CONNECTOR', 'JIRA', 'Incomplete', [
            'baseUrl' => 'https://',
            'direction' => 'BIDIRECTIONAL',
            'conflictStrategy' => 'MANUAL',
            'fieldOwnership' => ['status' => 'RISKPILOT'],
        ]);
    }

    public function testApiKeyLifecycleIsExplicitAndSafe(): void
    {
        $key = new PlatformIntegration(new Organization('Primary'), 'API_KEY', 'GENERIC', 'SIEM', [
            'scopes' => ['risks:read'],
            'expiresAt' => '2025-01-01T00:00:00+00:00',
        ]);
        $key->setCredential('rp_api_key_original');

        self::assertTrue($key->isCredentialConfigured());
        self::assertTrue($key->isCredentialExpired(new \DateTimeImmutable('2025-01-02T00:00:00+00:00')));

        $key->rotateCredential('rp_api_key_rotated', new \DateTimeImmutable('+30 days'));
        self::assertTrue($key->isEnabled());
        self::assertTrue($key->verifies('rp_api_key_rotated'));
        self::assertFalse($key->isCredentialExpired());

        $key->revokeCredential();
        self::assertFalse($key->isEnabled());
        self::assertFalse($key->isCredentialConfigured());
        self::assertFalse($key->verifies('rp_api_key_rotated'));
    }

    public function testExpirationCannotBeRemovedThroughAnOrdinaryUpdate(): void
    {
        $key = new PlatformIntegration(new Organization('Primary'), 'API_KEY', 'GENERIC', 'SIEM', [
            'scopes' => ['risks:read'],
            'expiresAt' => (new \DateTimeImmutable('+30 days'))->format(DATE_ATOM),
        ]);
        $key->setCredential('rp_api_key_original');
        $this->expectException(\InvalidArgumentException::class);
        $key->update('SIEM', ['scopes' => ['risks:read']], true);
    }
}
