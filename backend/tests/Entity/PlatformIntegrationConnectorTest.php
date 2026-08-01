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
}
