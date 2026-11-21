<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\OidcRoleMapper;
use PHPUnit\Framework\TestCase;

final class OidcRoleMapperTest extends TestCase
{
    private const CONFIGURATION = ['roleClaim' => 'realm_access.roles', 'roleMappings' => [['group' => 'auditors', 'role' => 'ROLE_AUDITOR'], ['group' => 'managers', 'role' => 'ROLE_RISK_MANAGER'], ['group' => 'bosses', 'role' => 'ROLE_ADMIN'], ['group' => 'root', 'role' => 'ROLE_SUPER_ADMIN']]];

    public function testGroupsFromANestedClaimMapToAssignableRolesOnly(): void
    {
        $mapper = new OidcRoleMapper();
        $roles = $mapper->mappedRoles(['realm_access' => ['roles' => ['auditors', 'managers', 'bosses', 'root', 'unknown']]], self::CONFIGURATION);
        self::assertSame([User::ROLE_AUDITOR, User::ROLE_RISK_MANAGER], $roles, 'Administration roles in a mapping are ignored.');
    }

    public function testNoConfigurationOrAMissingOrMalformedClaimLeavesRolesUntouched(): void
    {
        $mapper = new OidcRoleMapper();
        self::assertNull($mapper->mappedRoles(['groups' => ['auditors']], []));
        self::assertNull($mapper->mappedRoles(['groups' => ['auditors']], ['roleClaim' => 'groups', 'roleMappings' => []]));
        self::assertNull($mapper->mappedRoles([], self::CONFIGURATION));
        self::assertNull($mapper->mappedRoles(['realm_access' => ['roles' => 'auditors']], ['roleClaim' => 'realm_access.roles.x', 'roleMappings' => self::CONFIGURATION['roleMappings']]));
        self::assertNull($mapper->mappedRoles(['realm_access' => ['roles' => [1, 2]]], self::CONFIGURATION), 'Non-string groups are rejected.');
        self::assertSame([User::ROLE_AUDITOR], $mapper->mappedRoles(['groups' => 'auditors'], ['roleClaim' => 'groups', 'roleMappings' => self::CONFIGURATION['roleMappings']]), 'A single string claim is a one-group list.');
    }

    public function testMergeKeepsAdministrationRolesAndReplacesTheOthers(): void
    {
        $mapper = new OidcRoleMapper();
        self::assertSame([User::ROLE_ADMIN, User::ROLE_AUDITOR], $mapper->merge([User::ROLE_ADMIN, User::ROLE_RISK_MANAGER], [User::ROLE_AUDITOR]));
        self::assertSame([], $mapper->merge([User::ROLE_RISK_MANAGER, User::ROLE_ACTION_OWNER], []));
        self::assertSame([User::ROLE_SUPER_ADMIN], $mapper->merge([User::ROLE_SUPER_ADMIN, User::ROLE_AUDITOR], [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN]), 'A forged mapped list cannot add administration roles.');
    }
}
