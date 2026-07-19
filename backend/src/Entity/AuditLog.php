<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuditLogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: 'audit_logs')]
#[ORM\Index(columns: ['organization_id', 'created_at'], name: 'idx_audit_tenant_date')]
#[ORM\Index(columns: ['event_hash'], name: 'idx_audit_event_hash')]
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
    /** @var array<string, mixed>|null */ #[ORM\Column(type: 'json', nullable: true)] private ?array $oldValues;
    #[ORM\Column(length: 45, nullable: true)] private ?string $ipAddress;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $userAgent;
    #[ORM\Column(length: 64, nullable: true)] private ?string $previousHash = null;
    #[ORM\Column(length: 64, nullable: true)] private ?string $eventHash = null;
    #[ORM\Column(length: 36, nullable: true)] private ?string $requestId = null;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    /**
     * @param array<string, mixed>|null $newValues
     * @param array<string, mixed>|null $oldValues
     */
    public function __construct(Organization $organization, ?User $user, string $action, string $entityType, ?string $entityId, ?array $newValues, ?string $ipAddress, ?string $userAgent, ?array $oldValues = null)
    {
        $this->organization = $organization;
        $this->user = $user;
        $this->action = $action;
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->newValues = $newValues;
        $this->oldValues = $oldValues;
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

    /** @return array<string, mixed>|null */
    public function getOldValues(): ?array
    {
        return $this->oldValues;
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

    public function getPreviousHash(): ?string
    {
        return $this->previousHash;
    }

    public function getEventHash(): ?string
    {
        return $this->eventHash;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function seal(?string $previousHash, string $requestId): void
    {
        $this->previousHash = $previousHash;
        $this->requestId = $requestId;
        $this->eventHash = hash('sha256', ($previousHash ?? 'GENESIS').'|'.$this->canonicalPayload());
    }

    public function verify(?string $expectedPreviousHash): bool
    {
        if (null === $this->eventHash || null === $this->requestId || $this->previousHash !== $expectedPreviousHash) {
            return false;
        }

        return hash_equals($this->eventHash, hash('sha256', ($this->previousHash ?? 'GENESIS').'|'.$this->canonicalPayload()));
    }

    private function canonicalPayload(): string
    {
        $payload = [
            'organizationId' => $this->organization->getId(),
            'userId' => $this->user?->getId(),
            'action' => $this->action,
            'entityType' => $this->entityType,
            'entityId' => $this->entityId,
            'newValues' => $this->newValues,
            'ipAddress' => $this->ipAddress,
            'userAgent' => $this->userAgent,
            'requestId' => $this->requestId,
            'createdAt' => $this->createdAt->format('Y-m-d\TH:i:s.uP'),
        ];
        if (null !== $this->oldValues) {
            $payload['oldValues'] = $this->oldValues;
        }

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
