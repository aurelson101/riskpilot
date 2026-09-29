<?php

declare(strict_types=1);

namespace App\Application;

use App\Entity\PlatformIntegration;
use App\Security\SecretCipher;

final readonly class DirectoryConnectionTester
{
    public function __construct(private SecretCipher $cipher)
    {
    }

    /** @return array<string, mixed> */
    public function test(PlatformIntegration $integration): array
    {
        if (!function_exists('ldap_connect')) {
            throw new \RuntimeException('Extension LDAP indisponible.');
        }
        $configuration = $integration->getConfiguration();
        $encrypted = $integration->getEncryptedCredential();
        if (null === $encrypted) {
            throw new \RuntimeException('Credential de bind absent.');
        }

        $path = $this->temporaryCaCertificate((string) ($configuration['caCertificate'] ?? ''));
        $connection = null;
        try {
            $connection = ldap_connect((string) $configuration['host'], (int) $configuration['port']);
            if (false === $connection) {
                throw new \RuntimeException('Connexion LDAPS impossible.');
            }
            ldap_set_option($connection, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($connection, LDAP_OPT_REFERRALS, 0);
            ldap_set_option($connection, LDAP_OPT_NETWORK_TIMEOUT, 5);
            ldap_set_option($connection, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_DEMAND);
            if (null !== $path) {
                ldap_set_option($connection, LDAP_OPT_X_TLS_CACERTFILE, $path);
            }
            if (!@ldap_bind($connection, (string) $configuration['bindDn'], $this->cipher->decrypt($encrypted))) {
                throw new \RuntimeException('Bind LDAPS refusé. Vérifiez le certificat, le compte et le mot de passe.');
            }

            $username = (string) ($configuration['testUsername'] ?? 'riskpilot-validation');
            $filter = str_replace('{username}', ldap_escape($username, '', LDAP_ESCAPE_FILTER), (string) $configuration['userFilter']);
            $search = @ldap_search($connection, (string) $configuration['baseDn'], $filter, ['dn', 'memberOf'], 0, 10, 5);
            if (false === $search) {
                throw new \RuntimeException('Recherche LDAP de validation refusée.');
            }
            $entries = ldap_get_entries($connection, $search);

            return [
                'validated' => true,
                'transport' => 'LDAPS',
                'certificateVerification' => 'required',
                'certificateAuthority' => null === $path ? 'system' : 'custom',
                'bind' => 'ok',
                'search' => 'ok',
                'matchedEntries' => (int) ($entries['count'] ?? 0),
                'groupMappingCount' => count((array) ($configuration['groupMappings'] ?? [])),
            ];
        } finally {
            if ($connection instanceof \LDAP\Connection) {
                @ldap_unbind($connection);
            }
            if (null !== $path && is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function temporaryCaCertificate(string $certificate): ?string
    {
        if ('' === trim($certificate)) {
            return null;
        }
        $path = tempnam(sys_get_temp_dir(), 'riskpilot-ca-');
        if (false === $path) {
            throw new \RuntimeException('Impossible de préparer la CA.');
        }
        if (strlen($certificate) !== file_put_contents($path, $certificate, LOCK_EX) || !chmod($path, 0600)) {
            @unlink($path);
            throw new \RuntimeException('Impossible de protéger la CA temporaire.');
        }

        return $path;
    }
}
