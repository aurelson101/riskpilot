<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlatformIntegrationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlatformIntegrationRepository::class)]
#[ORM\Table(name: 'platform_integrations')]
#[ORM\Index(columns: ['organization_id', 'type', 'enabled'], name: 'idx_integration_tenant_type')]
class PlatformIntegration
{
    public const TYPES = ['OIDC', 'SAML', 'SCIM', 'DIRECTORY', 'API_KEY', 'WEBHOOK', 'CONNECTOR'];
    public const PROVIDERS = ['GOOGLE_WORKSPACE', 'MICROSOFT_ENTRA', 'ACTIVE_DIRECTORY', 'JIRA', 'SERVICENOW', 'GENERIC'];
    public const SCOPES = ['risks:read', 'controls:read', 'actions:read', 'events:write', 'scim:write'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne, ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Organization $organization;
    #[ORM\Column(length: 20)] private string $type;
    #[ORM\Column(length: 30)] private string $provider;
    #[ORM\Column(length: 120)] private string $name;
    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')] private array $configuration;
    #[ORM\Column(length: 16, nullable: true)] private ?string $credentialPrefix = null;
    #[ORM\Column(length: 64, nullable: true)] private ?string $secretHash = null;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $encryptedCredential = null;
    #[ORM\Column] private bool $enabled;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    #[ORM\Column] private \DateTimeImmutable $updatedAt;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $lastUsedAt = null;
    /** @param array<string, mixed> $configuration */
    public function __construct(Organization $organization, string $type, string $provider, string $name, array $configuration, bool $enabled = false)
    {
        $type = strtoupper(trim($type));
        $provider = strtoupper(trim($provider));
        if (!in_array($type, self::TYPES, true) || !in_array($provider, self::PROVIDERS, true) || '' === trim($name)) {
            throw new \InvalidArgumentException('Type, fournisseur ou nom d’intégration invalide.');
        }
        if ($enabled && in_array($type, ['OIDC', 'SAML'], true)) {
            throw new \InvalidArgumentException('La connexion SSO n’est pas encore raccordée : conservez cette configuration inactive.');
        }
        $this->validateConfiguration($type, $configuration);
        $this->organization = $organization;
        $this->type = $type;
        $this->provider = $provider;
        $this->name = trim($name);
        $this->configuration = $configuration;
        $this->enabled = $enabled;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** @return array<string, mixed> */
    public function getConfiguration(): array
    {
        return $this->configuration;
    }

    public function getCredentialPrefix(): ?string
    {
        return $this->credentialPrefix;
    }

    public function getEncryptedCredential(): ?string
    {
        return $this->encryptedCredential;
    }

    public function setEncryptedCredential(string $value): void
    {
        if ('DIRECTORY' !== $this->type || '' === $value) {
            throw new \LogicException('Credential annuaire invalide.');
        }
        $this->encryptedCredential = $value;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function isCredentialConfigured(): bool
    {
        return 'DIRECTORY' === $this->type ? null !== $this->encryptedCredential : null !== $this->secretHash;
    }

    public function getCredentialExpiresAt(): ?\DateTimeImmutable
    {
        $value = $this->configuration['expiresAt'] ?? null;
        if ('API_KEY' !== $this->type || !is_string($value) || '' === $value) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    public function isCredentialExpired(?\DateTimeImmutable $now = null): bool
    {
        $expiresAt = $this->getCredentialExpiresAt();

        return null !== $expiresAt && $expiresAt <= ($now ?? new \DateTimeImmutable());
    }

    public function setCredential(string $plainSecret): void
    {
        if (!in_array($this->type, ['API_KEY', 'WEBHOOK', 'CONNECTOR'], true)) {
            throw new \LogicException('Cette intégration ne porte pas de secret technique.');
        }
        $this->credentialPrefix = substr($plainSecret, 0, 12);
        $this->secretHash = hash('sha256', $plainSecret);
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function verifies(string $plainSecret): bool
    {
        return null !== $this->secretHash && hash_equals($this->secretHash, hash('sha256', $plainSecret));
    }

    public function rotateCredential(string $plainSecret, \DateTimeImmutable $expiresAt): void
    {
        if ('API_KEY' !== $this->type || $expiresAt <= new \DateTimeImmutable()) {
            throw new \InvalidArgumentException('Rotation de clé API invalide.');
        }
        $this->configuration['expiresAt'] = $expiresAt->format(DATE_ATOM);
        $this->setCredential($plainSecret);
        $this->enabled = true;
    }

    public function revokeCredential(): void
    {
        if ('API_KEY' !== $this->type) {
            throw new \LogicException('Seule une clé API peut être révoquée.');
        }
        $this->credentialPrefix = null;
        $this->secretHash = null;
        $this->enabled = false;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function sign(string $payload, int $timestamp): string
    {
        if ('WEBHOOK' !== $this->type || null === $this->secretHash) {
            throw new \LogicException('Signature indisponible pour cette intégration.');
        }

        return hash_hmac('sha256', $timestamp.'.'.$payload, $this->secretHash);
    }

    public function markUsed(): bool
    {
        $now = new \DateTimeImmutable();
        if (null !== $this->lastUsedAt && $this->lastUsedAt > $now->modify('-5 minutes')) {
            return false;
        }
        $this->lastUsedAt = $now;

        return true;
    }

    /** @param array<string, mixed> $configuration */
    public function update(string $name, array $configuration, bool $enabled): void
    {
        if ('API_KEY' === $this->type && ($configuration['expiresAt'] ?? null) !== ($this->configuration['expiresAt'] ?? null)) {
            throw new \InvalidArgumentException('Utilisez la rotation pour modifier la validité de la clé API.');
        }
        if ('' === trim($name)) {
            throw new \InvalidArgumentException('Le nom est obligatoire.');
        }
        if ($enabled && in_array($this->type, ['OIDC', 'SAML'], true)) {
            throw new \InvalidArgumentException('La connexion SSO n’est pas encore raccordée : conservez cette configuration inactive.');
        }
        if ($enabled && 'API_KEY' === $this->type && (!$this->isCredentialConfigured() || $this->isCredentialExpired())) {
            throw new \InvalidArgumentException('La clé API est révoquée ou expirée : effectuez une rotation avant de l’activer.');
        }
        $this->validateConfiguration($this->type, $configuration);
        $this->name = trim($name);
        $this->configuration = $configuration;
        $this->enabled = $enabled;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** @param array<string, mixed> $configuration */
    private function validateConfiguration(string $type, array $configuration): void
    {
        if (in_array($type, ['OIDC', 'SAML'], true) && '' === trim((string) ($configuration['issuer'] ?? ''))) {
            throw new \InvalidArgumentException('L’émetteur de l’identité est obligatoire.');
        }
        if ('API_KEY' === $type) {
            $scopes = array_values(array_unique(array_map('strval', (array) ($configuration['scopes'] ?? []))));
            if ([] === $scopes || [] !== array_diff($scopes, self::SCOPES)) {
                throw new \InvalidArgumentException('Les portées de la clé API sont invalides.');
            }
            if (isset($configuration['expiresAt'])) {
                try {
                    new \DateTimeImmutable((string) $configuration['expiresAt']);
                } catch (\Exception) {
                    throw new \InvalidArgumentException('La date d’expiration de la clé API est invalide.');
                }
            }
        }
        if ('WEBHOOK' === $type) {
            $url = (string) ($configuration['url'] ?? '');
            if (!$this->validHttpsUrl($url)) {
                throw new \InvalidArgumentException('Un webhook HTTPS est obligatoire.');
            }
        }
        if ('CONNECTOR' === $type) {
            $url = (string) ($configuration['baseUrl'] ?? '');
            $direction = strtoupper((string) ($configuration['direction'] ?? ''));
            $conflictStrategy = strtoupper((string) ($configuration['conflictStrategy'] ?? ''));
            $fieldOwnership = (array) ($configuration['fieldOwnership'] ?? []);
            if (!$this->validHttpsUrl($url) || !in_array($direction, ['IMPORT', 'EXPORT', 'BIDIRECTIONAL'], true) || !in_array($conflictStrategy, ['SOURCE_WINS', 'RISKPILOT_WINS', 'MANUAL'], true) || [] === $fieldOwnership) {
                throw new \InvalidArgumentException('Le connecteur exige une URL HTTPS, un sens, une stratégie de conflit et la propriété des champs.');
            }
        }
        if ('DIRECTORY' === $type) {
            $host = trim((string) ($configuration['host'] ?? ''));
            $port = (int) ($configuration['port'] ?? 636);
            $baseDn = trim((string) ($configuration['baseDn'] ?? ''));
            $bindDn = trim((string) ($configuration['bindDn'] ?? ''));
            $userFilter = trim((string) ($configuration['userFilter'] ?? ''));
            $testUsername = trim((string) ($configuration['testUsername'] ?? 'riskpilot-validation'));
            $groupMappings = (array) ($configuration['groupMappings'] ?? []);
            $ca = trim((string) ($configuration['caCertificate'] ?? ''));
            $parts = parse_url($host);
            $validHost = is_array($parts)
                && 'ldaps' === strtolower((string) ($parts['scheme'] ?? ''))
                && '' !== (string) ($parts['host'] ?? '')
                && !isset($parts['user'])
                && !isset($parts['pass'])
                && !isset($parts['query'])
                && !isset($parts['fragment'])
                && (!isset($parts['path']) || '' === $parts['path'])
                && (!isset($parts['port']) || 636 === $parts['port']);
            $validUsername = '' !== $testUsername && 180 >= strlen($testUsername) && !preg_match('/[\x00-\x1F\x7F]/', $testUsername);
            if (!$validHost || 636 !== $port || '' === $baseDn || '' === $bindDn || !str_contains($userFilter, '{username}') || !$validUsername || [] === $groupMappings || ('' !== $ca && (!str_contains($ca, 'BEGIN CERTIFICATE') || !str_contains($ca, 'END CERTIFICATE')))) {
                throw new \InvalidArgumentException('LDAPS exige ldaps://, port 636, base DN, bind DN, filtre utilisateur, groupes et une CA PEM valide si fournie.');
            }
        }
    }

    private function validHttpsUrl(string $url): bool
    {
        if (false === filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || 'https' !== strtolower((string) ($parts['scheme'] ?? '')) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $host = (string) ($parts['host'] ?? '');
        if (false !== filter_var($host, FILTER_VALIDATE_IP)) {
            return false !== filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        return str_contains($host, '.') && false !== filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
    }
}
