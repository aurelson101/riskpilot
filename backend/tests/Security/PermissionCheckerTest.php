<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Organization;
use App\Entity\User;
use App\Security\PermissionChecker;
use PHPUnit\Framework\TestCase;

final class PermissionCheckerTest extends TestCase
{
    public function testDefaultsCanBeRestrictedPerOrganization(): void
    {
        $organization = new Organization('Tenant');
        $manager = new User('manager@example.test', 'Risk', 'Manager', $organization, [User::ROLE_RISK_MANAGER]);
        $checker = new PermissionChecker();
        self::assertTrue($checker->isGranted($manager, 'ebios.update'));

        $organization->setRolePermissions([User::ROLE_RISK_MANAGER => ['ebios.read']]);
        self::assertFalse($checker->isGranted($manager, 'ebios.update'));
        self::assertTrue($checker->isGranted($manager, 'ebios.read'));
    }

    public function testSuperAdministratorCannotBeLockedOutByOrganizationOverrides(): void
    {
        $organization = new Organization('Tenant');
        $organization->setRolePermissions([User::ROLE_SUPER_ADMIN => []]);
        $superAdmin = new User('super-admin@example.test', 'Super', 'Admin', $organization, [User::ROLE_SUPER_ADMIN]);

        self::assertTrue((new PermissionChecker())->isGranted($superAdmin, 'admin.roles'));
    }
}
