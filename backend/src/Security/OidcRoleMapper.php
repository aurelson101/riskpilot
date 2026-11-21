<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;

/**
 * Traduit les groupes ou rôles d'un jeton d'identité OIDC en rôles RiskPilot, selon une correspondance
 * déclarée par l'administrateur. Les rôles d'administration ne sont jamais attribués ni retirés par le
 * fournisseur d'identité : ils restent gérés dans RiskPilot.
 */
final class OidcRoleMapper
{
    /** Rôles qu'une correspondance peut attribuer. */
    public const ASSIGNABLE_ROLES = [User::ROLE_RISK_MANAGER, User::ROLE_ACTION_OWNER, User::ROLE_AUDITOR, User::ROLE_VIEWER];
    private const PROTECTED_ROLES = [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN];

    /**
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $configuration
     *
     * @return list<string>|null null lorsqu'aucune correspondance n'est configurée ou que la revendication est absente ou invalide
     */
    public function mappedRoles(array $claims, array $configuration): ?array
    {
        $mappings = $configuration['roleMappings'] ?? [];
        $path = $configuration['roleClaim'] ?? null;
        if (!is_array($mappings) || [] === $mappings || !is_string($path) || '' === $path) {
            return null;
        }
        $groups = $this->groups($claims, $path);
        if (null === $groups) {
            return null;
        }
        $roles = [];
        foreach ($mappings as $mapping) {
            if (is_array($mapping) && is_string($mapping['group'] ?? null) && in_array($mapping['group'], $groups, true)
                && is_string($mapping['role'] ?? null) && in_array($mapping['role'], self::ASSIGNABLE_ROLES, true)) {
                $roles[$mapping['role']] = true;
            }
        }

        return array_keys($roles);
    }

    /**
     * @param list<string> $currentRoles
     * @param list<string> $mappedRoles
     *
     * @return list<string>
     */
    public function merge(array $currentRoles, array $mappedRoles): array
    {
        $preserved = array_values(array_intersect($currentRoles, self::PROTECTED_ROLES));

        return array_values(array_unique([...$preserved, ...array_values(array_intersect($mappedRoles, self::ASSIGNABLE_ROLES))]));
    }

    /**
     * @param array<string, mixed> $claims
     *
     * @return list<string>|null
     */
    private function groups(array $claims, string $path): ?array
    {
        $value = $claims;
        // Les attributs SAML portent souvent des noms qui contiennent des points ou des barres (urn:oid:2.5.4.3, URL de schéma).
        $segments = array_key_exists($path, $claims) ? [$path] : explode('.', $path);
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }
        if (is_string($value)) {
            return [$value];
        }
        if (is_array($value) && array_is_list($value) && count($value) === count(array_filter($value, 'is_string'))) {
            return $value;
        }

        return null;
    }
}
