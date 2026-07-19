<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuditLogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: 'audit_logs')]
#[ORM\Index(columns: ['organization_id', 'created_at'], name: 'idx_audit_tenant_date')]
class AuditLog
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Organization $organization;

    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user;

    #[ORM\Column(length: 20)] private string $action;
    #[ORM\Column(length: 100)] private string $entityType;
    #[ORM\Column(length: 100, nullable: true)] private ?string $entityId;
    /** @var array<string, mixed>|null */ #[ORM\Column(type: 'json', nullable: true)] private ?array $newValues;
    #[ORM\Column(length: 45, nullable: true)] private ?string $ipAddress;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $userAgent;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    /** @param array<string, mixed>|null $newValues */
    public function __construct(Organization $organization, ?User $user, string $action, string $entityType, ?string $entityId, ?array $newValues, ?string $ipAddress, ?string $userAgent)
    {
        $this->organization = $organization;
        $this->user = $user;
        $this->action = $action;
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->newValues = $newValues;
        $this->ipAddress = $ipAddress;
        $this->userAgent = $userAgent;
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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getEntityId(): ?string
    {
        return $this->entityId;
    }

    /** @return array<string, mixed>|null */
    public function getNewValues(): ?array
    {
        return $this->newValues;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
