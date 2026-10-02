<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Organization;
use App\Entity\PlatformIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlatformIntegrationValidationTest extends TestCase
{
    #[DataProvider('invalidConfigurations')]
    public function testInvalidConfigurationIsRejected(string $type, string $name, array $config): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PlatformIntegration(new Organization('Tenant'), $type, 'GENERIC', $name, $config);
    }

    public static function invalidConfigurations(): iterable
    {
        yield 'name too long' => ['API_KEY', str_repeat('a', 121), ['scopes' => ['risks:read']]];
        yield 'name control' => ['API_KEY', "SIEM\nAdmin", ['scopes' => ['risks:read']]];
        yield 'configuration size' => ['API_KEY', 'SIEM', ['scopes' => ['risks:read'], 'notes' => str_repeat('a', 65536)]];
        yield 'nested plain secret' => ['API_KEY', 'SIEM', ['scopes' => ['risks:read'], 'auth' => ['client_secret' => 'never-store-this']]];
        yield 'scopes not list' => ['API_KEY', 'SIEM', ['scopes' => ['scope' => 'risks:read']]];
        yield 'scope scalar' => ['API_KEY', 'SIEM', ['scopes' => 'risks:read']];
        yield 'scope wrong type' => ['API_KEY', 'SIEM', ['scopes' => [1]]];
        yield 'relative expiry' => ['API_KEY', 'SIEM', ['scopes' => ['risks:read'], 'expiresAt' => '+30 days']];
        yield 'invalid date' => ['API_KEY', 'SIEM', ['scopes' => ['risks:read'], 'expiresAt' => '2030-02-30T00:00:00+00:00']];
        yield 'directory port type' => ['DIRECTORY', 'AD', ['port' => '636']];
        yield 'directory text field type' => ['DIRECTORY', 'AD', ['host' => ['ldaps://ad.example.test']]];
        yield 'connector ownership type' => ['CONNECTOR', 'Jira', ['baseUrl' => 'https://jira.example.test', 'direction' => 'IMPORT', 'conflictStrategy' => 'MANUAL', 'fieldOwnership' => ['status' => ['JIRA']]]];
        yield 'webhook fragment' => ['WEBHOOK', 'Hook', ['url' => 'https://hook.example.test/#secret']];
        yield 'OIDC insecure issuer' => ['OIDC', 'SSO', ['issuer' => 'http://id.example.test']];
    }

    public function testUpdateAlsoRejectsOversizedNames(): void
    {
        $config = ['scopes' => ['risks:read']];
        $key = new PlatformIntegration(new Organization('Tenant'), 'API_KEY', 'GENERIC', 'SIEM', $config);
        $this->expectException(\InvalidArgumentException::class);
        $key->update(str_repeat('a', 121), $config, false);
    }
}
