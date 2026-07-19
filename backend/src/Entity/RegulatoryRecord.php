<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RegulatoryRecordRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RegulatoryRecordRepository::class)] #[ORM\Table(name: 'regulatory_records')]
class RegulatoryRecord
{
    public const TYPES = ['PROCESSING_ACTIVITY', 'DPIA', 'DATA_BREACH', 'OBLIGATION', 'EXCEPTION'];
    public const STATUSES = ['DRAFT', 'ACTIVE', 'IN_REVIEW', 'APPROVED', 'COMPLIANT', 'NON_COMPLIANT', 'CLOSED', 'EXPIRED'];
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Organization $organization;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private User $owner;
    #[ORM\Column(length: 30)] private string $type;
    #[ORM\Column(length: 220)] private string $title;
    #[ORM\Column(length: 20)] private string $status = 'DRAFT';
    /** @var array<string, mixed> */ #[ORM\Column(type: 'json')] private array $details;
    /** @var list<string> */ #[ORM\Column(type: 'json')] private array $evidence;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $dueAt = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $expiresAt = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')] private ?User $approvedBy = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $approvedAt = null;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    #[ORM\Column] private \DateTimeImmutable $updatedAt;
    /**
     * @param array<string, mixed> $details
     * @param list<string>         $evidence
     */
    public function __construct(Organization $organization, User $owner, string $type, string $title, array $details, array $evidence, ?\DateTimeImmutable $dueAt, ?\DateTimeImmutable $expiresAt)
    {
        $this->organization = $organization;
        $this->owner = $owner;
        $this->type = $type;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
        $this->update($title, 'DRAFT', $details, $evidence, $dueAt, $expiresAt, $owner);
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

    /** @return list<string> */
    public function getEvidence(): array
    {
        return $this->evidence;
    }

    public function getDueAt(): ?\DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getApprovedBy(): ?User
    {
        return $this->approvedBy;
    }

    public function getApprovedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }

    /**
     * @param array<string, mixed> $details
     * @param list<string>         $evidence
     */
    public function update(string $title, string $status, array $details, array $evidence, ?\DateTimeImmutable $dueAt, ?\DateTimeImmutable $expiresAt, User $owner): void
    {
        if ('' === trim($title) || !in_array($this->type, self::TYPES, true) || !in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Enregistrement réglementaire invalide.');
        }
        $rules = ['PROCESSING_ACTIVITY' => ['purpose', 'dataCategories', 'legalBasis', 'retention', 'recipients'], 'DPIA' => ['processing', 'necessity', 'risks', 'measures'], 'DATA_BREACH' => ['nature', 'affectedSubjects', 'consequences', 'measures', 'notificationDecision'], 'OBLIGATION' => ['source', 'requirement', 'applicability'], 'EXCEPTION' => ['justification', 'risk', 'compensatingMeasure']];
        $required = $rules[$this->type];
        foreach ($required as $key) {
            if (!isset($details[$key]) || '' === trim(is_array($details[$key]) ? implode('', $details[$key]) : (string) $details[$key])) {
                throw new \InvalidArgumentException(sprintf('Le champ %s est obligatoire pour %s.', $key, $this->type));
            }
        } if ('EXCEPTION' === $this->type && null === $expiresAt) {
            throw new \InvalidArgumentException('Une dérogation doit expirer.');
        } $this->title = trim($title);
        $this->status = $status;
        $this->details = $details;
        $this->evidence = $evidence;
        $this->dueAt = $dueAt;
        $this->expiresAt = $expiresAt;
        $this->owner = $owner;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function approve(User $approver): void
    {
        if ('EXCEPTION' !== $this->type || $approver === $this->owner || null === $this->expiresAt || $this->expiresAt <= new \DateTimeImmutable()) {
            throw new \LogicException('La dérogation exige un approbateur indépendant et une expiration future.');
        } $this->status = 'APPROVED';
        $this->approvedBy = $approver;
        $this->approvedAt = $this->updatedAt = new \DateTimeImmutable();
    }
}
