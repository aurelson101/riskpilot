<?php

declare(strict_types=1);

namespace App\Application;

use App\Entity\PlatformIntegration;
use App\Security\SecretCipher;

final readonly class DirectoryConnectionTester
{
    public function __construct(private SecretCipher $cipher) {}
    /** @return array<string, mixed> */
    public function test(PlatformIntegration $integration): array
    {
        if (!function_exists('ldap_connect')) throw new \RuntimeException('Extension LDAP indisponible.');
        $configuration = $integration->getConfiguration(); $encrypted = $integration->getEncryptedCredential(); if (null === $encrypted) throw new \RuntimeException('Credential de bind absent.');
        if (!empty($configuration['caCertificate'])) { $path = tempnam(sys_get_temp_dir(), 'riskpilot-ca-'); if (false === $path) throw new \RuntimeException('Impossible de préparer la CA.'); file_put_contents($path, (string) $configuration['caCertificate']); putenv('LDAPTLS_CACERT='.$path); }
        else { $path = null; }
        try {
            $connection = ldap_connect((string) $configuration['host'], (int) $configuration['port']); if (false === $connection) throw new \RuntimeException('Connexion LDAPS impossible.'); ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3); ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);
            if (!@ldap_bind($connection, (string) $configuration['bindDn'], $this->cipher->decrypt($encrypted))) throw new \RuntimeException('Bind LDAPS refusé.');
            $filter = str_replace('{username}', ldap_escape((string) ($configuration['testUsername'] ?? 'riskpilot-validation'), '', LDAP_ESCAPE_FILTER), (string) $configuration['userFilter']); $search = @ldap_search($connection, (string) $configuration['baseDn'], $filter, ['dn', 'memberOf']); if (false === $search) throw new \RuntimeException('Recherche LDAP de validation refusée.'); $entries = ldap_get_entries($connection, $search);
            return ['validated' => true, 'transport' => 'LDAPS', 'tlsVerified' => !empty($configuration['caCertificate']), 'bind' => 'ok', 'search' => 'ok', 'matchedEntries' => (int) ($entries['count'] ?? 0), 'groupMappings' => array_keys((array) ($configuration['groupMappings'] ?? []))];
        } finally {
            if (null !== $path) {
                putenv('LDAPTLS_CACERT');
                if (is_file($path)) @unlink($path);
            }
        }
    }
}
