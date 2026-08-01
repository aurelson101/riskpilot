<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AnalysisArtifactRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AnalysisArtifactRepository::class)]
#[ORM\Table(name: 'analysis_artifacts')]
#[ORM\UniqueConstraint(name: 'uniq_artifact_idempotency', columns: ['organization_id', 'idempotency_key'])]
#[ORM\Index(columns: ['organization_id', 'kind', 'status'], name: 'idx_artifact_tenant')]
class AnalysisArtifact
{
    public const KINDS = ['METHOD_STEP', 'EVIDENCE', 'CONTROL_EFFECTIVENESS', 'TREATMENT_SCENARIO', 'ROADMAP_OPTION', 'ACL_GRANT', 'ACTIVITY', 'IMPORT_BATCH', 'LIBRARY_UPDATE', 'SUPPLIER_TIER', 'PRODUCT_METRIC'];
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Organization $organization;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')] private ?RiskAnalysis $analysis;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private User $owner;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')] private ?User $approvedBy = null;
    #[ORM\Column(length: 40)] private string $kind;
    #[ORM\Column(length: 160)] private string $title;
    /** @var array<array-key, mixed> */ #[ORM\Column(type: 'json')] private array $payload;
    #[ORM\Column(length: 20)] private string $status = 'DRAFT';
    #[ORM\Column(name: 'idempotency_key', length: 100)] private string $idempotencyKey;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $approvedAt = null;
    /** @param array<array-key, mixed> $payload */
    public function __construct(Organization $organization, ?RiskAnalysis $analysis, User $owner, string $kind, string $title, array $payload, string $idempotencyKey)
    {
        if (!in_array($kind, self::KINDS, true) || '' === trim($title) || '' === trim($idempotencyKey)) {
            throw new \InvalidArgumentException('Artefact invalide.');
        } if (null !== $analysis && $analysis->getOrganization() !== $organization) {
            throw new \InvalidArgumentException('Analyse étrangère.');
        } $this->organization = $organization;
        $this->analysis = $analysis;
        $this->owner = $owner;
        $this->kind = $kind;
        $this->title = trim($title);
        $this->payload = $payload;
        $this->idempotencyKey = trim($idempotencyKey);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function approve(User $approver): void
    {
        if ($approver === $this->owner || $approver->getOrganization() !== $this->organization) {
            throw new \LogicException('Approbation indépendante invalide.');
        } $this->status = 'APPROVED';
        $this->approvedBy = $approver;
        $this->approvedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getAnalysis(): ?RiskAnalysis
    {
        return $this->analysis;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getApprovedBy(): ?User
    {
        return $this->approvedBy;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    /** @return array<array-key, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getIdempotencyKey(): string
    {
        return $this->idempotencyKey;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getApprovedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }
}
