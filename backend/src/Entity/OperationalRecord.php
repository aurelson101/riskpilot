<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OperationalRecordRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OperationalRecordRepository::class)]
#[ORM\Table(name: 'operational_records')]
#[ORM\Index(columns: ['organization_id', 'type', 'status'], name: 'idx_operational_record_lookup')]
class OperationalRecord
{
    public const TYPES = ['TASK', 'RESPONSIBILITY_RULE', 'COMPLIANCE_PROGRAM', 'QUESTIONNAIRE_TEMPLATE', 'QUESTIONNAIRE_CAMPAIGN', 'REFERENCE_PACK', 'SECURITY_PROJECT', 'SAVED_VIEW', 'REPORT_TEMPLATE', 'REPORT_RUN', 'CONNECTOR_SYNC', 'TPRM_PROGRAM', 'P3_SETTINGS'];
    public const STATUSES = ['DRAFT', 'ACTIVE', 'IN_PROGRESS', 'AT_RISK', 'COMPLETED', 'ARCHIVED'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Organization $organization;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')] private ?User $owner = null;
    #[ORM\Column(length: 40)] private string $type;
    #[ORM\Column(length: 220)] private string $title;
    #[ORM\Column(length: 30)] private string $status = 'DRAFT';
    /** @var array<string, mixed> */ #[ORM\Column(type: 'json')] private array $details = [];
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $dueAt = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $lastReminderAt = null;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    #[ORM\Column] private \DateTimeImmutable $updatedAt;
    /** @param array<string, mixed> $details */
    public function __construct(Organization $organization, string $type, string $title, array $details = [])
    {
        if (!in_array($type, self::TYPES, true) || '' === trim($title)) {
            throw new \InvalidArgumentException('Invalid operational record.');
        }
        $this->organization = $organization;
        $this->type = $type;
        $this->title = trim($title);
        $this->details = $details;
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

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /** @return array<string, mixed> */
    public function getDetails(): array
    {
        return $this->details;
    }

    public function getDueAt(): ?\DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function getLastReminderAt(): ?\DateTimeImmutable
    {
        return $this->lastReminderAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @param array<string, mixed> $details */
    public function update(string $title, string $status, array $details, ?User $owner, ?\DateTimeImmutable $dueAt): void
    {
        if ('' === trim($title) || !in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Invalid operational record.');
        }
        if (null !== $owner && $owner->getOrganization() !== $this->organization) {
            throw new \InvalidArgumentException('Owner belongs to another organization.');
        }
        $this->title = trim($title);
        $this->status = $status;
        $this->details = $details;
        $this->owner = $owner;
        $this->dueAt = $dueAt;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markReminded(): void
    {
        $this->lastReminderAt = new \DateTimeImmutable();
        $this->updatedAt = $this->lastReminderAt;
    }
}
