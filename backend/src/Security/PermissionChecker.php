<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;

final class PermissionChecker
{
    public const ALL = [
        'risk.read', 'risk.create', 'risk.update', 'risk.delete',
        'ebios.read', 'ebios.create', 'ebios.update', 'ebios.validate',
        'nis2.read', 'nis2.update', 'evidence.upload',
        'action.read', 'action.update', 'admin.users', 'admin.roles', 'admin.settings',
    ];

    /** @var array<string, list<string>> */
    private const DEFAULTS = [
        User::ROLE_SUPER_ADMIN => ['*'],
        User::ROLE_ADMIN => ['risk.read', 'risk.create', 'risk.update', 'risk.delete', 'ebios.read', 'ebios.create', 'ebios.update', 'ebios.validate', 'nis2.read', 'nis2.update', 'evidence.upload', 'action.read', 'action.update', 'admin.users', 'admin.roles', 'admin.settings'],
        User::ROLE_RISK_MANAGER => ['risk.read', 'risk.create', 'risk.update', 'ebios.read', 'ebios.create', 'ebios.update', 'nis2.read', 'nis2.update', 'evidence.upload', 'action.read', 'action.update'],
        User::ROLE_AUDITOR => ['risk.read', 'ebios.read', 'ebios.validate', 'nis2.read', 'nis2.update', 'evidence.upload', 'action.read'],
        User::ROLE_ACTION_OWNER => ['risk.read', 'ebios.read', 'nis2.read', 'evidence.upload', 'action.read', 'action.update'],
        User::ROLE_VIEWER => ['risk.read', 'ebios.read', 'nis2.read', 'action.read'],
    ];

    public function isGranted(User $user, string $permission): bool
    {
        if (!in_array($permission, self::ALL, true)) {
            return false;
        }
        if (in_array(User::ROLE_SUPER_ADMIN, $user->getAssignedRoles(), true)) {
            return true;
        }
        $overrides = $user->getOrganization()->getRolePermissions();
        foreach ($user->getAssignedRoles() as $role) {
            $permissions = $overrides[$role] ?? self::DEFAULTS[$role] ?? [];
            if (in_array('*', $permissions, true) || in_array($permission, $permissions, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, list<string>> */
    public function effectiveMatrix(User $user): array
    {
        $configured = $user->getOrganization()->getRolePermissions();
        $matrix = [];
        foreach (User::ASSIGNABLE_ROLES as $role) {
            $matrix[$role] = $configured[$role] ?? self::DEFAULTS[$role];
        }

        return $matrix;
    }
}
