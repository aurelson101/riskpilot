<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\KnowledgeLibraryItemRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: KnowledgeLibraryItemRepository::class)]
#[ORM\Table(name: 'knowledge_library_items')]
#[ORM\UniqueConstraint(name: 'uniq_library_tenant_key_version', columns: ['organization_id', 'item_key', 'version_number'])]
#[ORM\Index(columns: ['organization_id', 'kind', 'status'], name: 'idx_library_tenant_kind')]
class KnowledgeLibraryItem
{
    public const KINDS = ['RISK_SCENARIO', 'ASSET', 'THREAT', 'VULNERABILITY', 'CONTROL', 'QUESTIONNAIRE', 'REPORT_TEMPLATE'];
    public const STATUSES = ['DRAFT', 'IN_REVIEW', 'APPROVED', 'RETIRED'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Organization $organization;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private User $owner;
    #[ORM\ManyToOne(targetEntity: self::class)] #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')] private ?self $supersedes = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')] private ?User $approvedBy = null;
    #[ORM\Column(name: 'item_key', length: 80)] private string $key;
    #[ORM\Column(length: 40)] private string $kind;
    #[ORM\Column(length: 220)] private string $title;
    #[ORM\Column(name: 'version_number')] private int $version;
    #[ORM\Column(length: 20)] private string $status = 'DRAFT';
    /** @var array<string, mixed> */ #[ORM\Column(type: 'json')] private array $content;
    /** @var list<array{key: string, minVersion: int}> */ #[ORM\Column(type: 'json')] private array $dependencies;
    #[ORM\Column(length: 250, nullable: true)] private ?string $source;
    #[ORM\Column(length: 120, nullable: true)] private ?string $license;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $approvedAt = null;
    /**
     * @param array<string, mixed>                      $content
     * @param list<array{key: string, minVersion: int}> $dependencies
     */
    public function __construct(Organization $organization, User $owner, string $key, string $kind, string $title, int $version, array $content, array $dependencies = [], ?string $source = null, ?string $license = null, ?self $supersedes = null)
    {
        if ($owner->getOrganization() !== $organization || !preg_match('/^[a-z0-9][a-z0-9._-]{2,79}$/', $key) || !in_array($kind, self::KINDS, true) || '' === trim($title) || $version < 1 || [] === $content) {
            throw new \InvalidArgumentException('Élément de bibliothèque invalide.');
        }
        if (null !== $supersedes && ($supersedes->getOrganization() !== $organization || $supersedes->getKey() !== $key || $version !== $supersedes->getVersion() + 1)) {
            throw new \InvalidArgumentException('Chaîne de versions invalide.');
        }
        $this->organization = $organization;
        $this->owner = $owner;
        $this->key = $key;
        $this->kind = $kind;
        $this->title = trim($title);
        $this->version = $version;
        $this->content = $content;
        $this->dependencies = $dependencies;
        $this->source = $this->nullable($source);
        $this->license = $this->nullable($license);
        $this->supersedes = $supersedes;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getSupersedes(): ?self
    {
        return $this->supersedes;
    }

    public function getApprovedBy(): ?User
    {
        return $this->approvedBy;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /** @return array<string, mixed> */
    public function getContent(): array
    {
        return $this->content;
    }

    /** @return list<array{key: string, minVersion: int}> */
    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function getLicense(): ?string
    {
        return $this->license;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getApprovedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function submit(): void
    {
        if ('DRAFT' !== $this->status) {
            throw new \LogicException('Seul un brouillon peut être soumis.');
        }
        $this->status = 'IN_REVIEW';
    }

    public function approve(User $approver): void
    {
        if ('IN_REVIEW' !== $this->status || $approver === $this->owner || $approver->getOrganization() !== $this->organization) {
            throw new \LogicException('Approbation de bibliothèque invalide.');
        }
        $this->status = 'APPROVED';
        $this->approvedBy = $approver;
        $this->approvedAt = new \DateTimeImmutable();
    }

    public function retire(): void
    {
        if ('APPROVED' !== $this->status) {
            throw new \LogicException('Seule une version approuvée peut être retirée.');
        }
        $this->status = 'RETIRED';
    }

    private function nullable(?string $value): ?string
    {
        return null === $value || '' === trim($value) ? null : trim($value);
    }
}
