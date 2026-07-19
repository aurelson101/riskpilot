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
    public const TYPES = ['OIDC', 'SAML', 'SCIM', 'API_KEY', 'WEBHOOK'];
    public const PROVIDERS = ['GOOGLE_WORKSPACE', 'MICROSOFT_ENTRA', 'GENERIC'];
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

    public function setCredential(string $plainSecret): void
    {
        if ('API_KEY' !== $this->type && 'WEBHOOK' !== $this->type) {
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

    public function sign(string $payload, int $timestamp): string
    {
        if ('WEBHOOK' !== $this->type || null === $this->secretHash) {
            throw new \LogicException('Signature indisponible pour cette intégration.');
        }

        return hash_hmac('sha256', $timestamp.'.'.$payload, $this->secretHash);
    }

    public function markUsed(): void
    {
        $this->lastUsedAt = new \DateTimeImmutable();
    }

    /** @param array<string, mixed> $configuration */
    public function update(string $name, array $configuration, bool $enabled): void
    {
        if ('' === trim($name)) {
            throw new \InvalidArgumentException('Le nom est obligatoire.');
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
        }
        if ('WEBHOOK' === $type) {
            $url = (string) ($configuration['url'] ?? '');
            if (!str_starts_with($url, 'https://')) {
                throw new \InvalidArgumentException('Un webhook HTTPS est obligatoire.');
            }
        }
    }
}
