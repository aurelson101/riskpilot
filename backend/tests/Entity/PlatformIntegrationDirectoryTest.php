<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Organization;
use App\Entity\PlatformIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlatformIntegrationDirectoryTest extends TestCase
{
    public function testOpenLdapDiagnosticConfigurationUsesGenericProvider(): void
    {
        $item = new PlatformIntegration(new Organization('Tenant'), 'DIRECTORY', 'GENERIC', 'OpenLDAP', [...$this->validConfiguration(), 'userFilter' => '(&(objectClass=inetOrgPerson)(uid={username}))']);
        self::assertSame('GENERIC', $item->getProvider());
        self::assertFalse($item->isEnabled());
    }
    public function testLastUseTrackingAvoidsWritingOnEveryApiRead(): void
    {
        $integration = new PlatformIntegration(new Organization('Tenant'), 'API_KEY', 'GENERIC', 'Reader', ['scopes' => ['risks:read']], true);

        self::assertTrue($integration->markUsed());
        $firstUse = $integration->getLastUsedAt();
        self::assertFalse($integration->markUsed());
        self::assertSame($firstUse, $integration->getLastUsedAt());
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('invalidConfigurationProvider')]
    public function testItRejectsUnsafeDirectoryConfiguration(array $override): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PlatformIntegration(new Organization('Tenant'), 'DIRECTORY', 'ACTIVE_DIRECTORY', 'AD', [...$this->validConfiguration(), ...$override]);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidConfigurationProvider(): iterable
    {
        yield 'credentials in URL' => [['host' => 'ldaps://user:password@ad.example.test']];
        yield 'query in URL' => [['host' => 'ldaps://ad.example.test?tls=off']];
        yield 'wrong embedded port' => [['host' => 'ldaps://ad.example.test:1636']];
        yield 'control character in username' => [['testUsername' => "alice\nadmin"]];
    }

    /** @return array<string, mixed> */
    private function validConfiguration(): array
    {
        return [
            'host' => 'ldaps://ad.example.test',
            'port' => 636,
            'baseDn' => 'DC=example,DC=test',
            'bindDn' => 'CN=riskpilot,OU=Services,DC=example,DC=test',
            'userFilter' => '(&(objectClass=user)(sAMAccountName={username}))',
            'testUsername' => 'alice',
            'groupMappings' => ['CN=Risk Managers,OU=Groups,DC=example,DC=test' => 'ROLE_RISK_MANAGER'],
        ];
    }
}
