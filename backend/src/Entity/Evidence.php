<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EvidenceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EvidenceRepository::class)]
#[ORM\Table(name: 'evidence_records')]
#[ORM\Index(columns: ['organization_id', 'status', 'expires_at'], name: 'idx_evidence_tenant_review')]
class Evidence
{
    public const KINDS = ['FILE', 'EXTERNAL_REFERENCE', 'ATTESTATION', 'TEST_RESULT', 'AUDIT_RECORD'];
    public const CLASSIFICATIONS = ['PUBLIC', 'INTERNAL', 'CONFIDENTIAL', 'RESTRICTED'];
    public const STATUSES = ['DRAFT', 'IN_REVIEW', 'APPROVED', 'REJECTED', 'EXPIRED', 'SUPERSEDED'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Organization $organization;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private User $owner;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')] private ?User $approvedBy = null;
    #[ORM\ManyToOne(targetEntity: self::class)] #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')] private ?self $supersedes = null;
    #[ORM\Column(length: 220)] private string $title;
    #[ORM\Column(length: 30)] private string $kind;
    #[ORM\Column(length: 30)] private string $classification;
    #[ORM\Column(length: 2048)] private string $sourceReference;
    #[ORM\Column(length: 64)] private string $sha256;
    #[ORM\Column] private int $version = 1;
    #[ORM\Column(length: 20)] private string $status = 'DRAFT';
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $validFrom = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $expiresAt = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $approvedAt = null;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    #[ORM\Column] private \DateTimeImmutable $updatedAt;
    /** @var Collection<int, Requirement> */ #[ORM\ManyToMany(targetEntity: Requirement::class)] #[ORM\JoinTable(name: 'evidence_requirements')] private Collection $requirements;
    /** @var Collection<int, SecurityControl> */ #[ORM\ManyToMany(targetEntity: SecurityControl::class)] #[ORM\JoinTable(name: 'evidence_controls')] private Collection $controls;
    /** @var Collection<int, ComplianceResult> */ #[ORM\ManyToMany(targetEntity: ComplianceResult::class)] #[ORM\JoinTable(name: 'evidence_results')] private Collection $results;
    /** @var Collection<int, ActionPlan> */ #[ORM\ManyToMany(targetEntity: ActionPlan::class)] #[ORM\JoinTable(name: 'evidence_actions')] private Collection $actions;

    public function __construct(Organization $organization, User $owner, string $title, string $kind, string $classification, string $sourceReference, string $sha256, ?\DateTimeImmutable $validFrom, ?\DateTimeImmutable $expiresAt, ?self $supersedes = null)
    {
        $this->organization = $organization;
        $this->owner = $owner;
        $this->supersedes = $supersedes;
        $this->version = null === $supersedes ? 1 : $supersedes->version + 1;
        $this->requirements = new ArrayCollection();
        $this->controls = new ArrayCollection();
        $this->results = new ArrayCollection();
        $this->actions = new ArrayCollection();
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
        $this->update($title, $kind, $classification, $sourceReference, $sha256, $validFrom, $expiresAt);
    }

    public function getId(): ?int { return $this->id; }
    public function getOrganization(): Organization { return $this->organization; }
    public function getOwner(): User { return $this->owner; }
    public function getApprovedBy(): ?User { return $this->approvedBy; }
    public function getSupersedes(): ?self { return $this->supersedes; }
    public function getTitle(): string { return $this->title; }
    public function getKind(): string { return $this->kind; }
    public function getClassification(): string { return $this->classification; }
    public function getSourceReference(): string { return $this->sourceReference; }
    public function getSha256(): string { return $this->sha256; }
    public function getVersion(): int { return $this->version; }
    public function getStatus(): string { return $this->status; }
    public function getValidFrom(): ?\DateTimeImmutable { return $this->validFrom; }
    public function getExpiresAt(): ?\DateTimeImmutable { return $this->expiresAt; }
    public function getApprovedAt(): ?\DateTimeImmutable { return $this->approvedAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    /** @return Collection<int, Requirement> */ public function getRequirements(): Collection { return $this->requirements; }
    /** @return Collection<int, SecurityControl> */ public function getControls(): Collection { return $this->controls; }
    /** @return Collection<int, ComplianceResult> */ public function getResults(): Collection { return $this->results; }
    /** @return Collection<int, ActionPlan> */ public function getActions(): Collection { return $this->actions; }

    public function update(string $title, string $kind, string $classification, string $sourceReference, string $sha256, ?\DateTimeImmutable $validFrom, ?\DateTimeImmutable $expiresAt): void
    {
        $kind = strtoupper($kind); $classification = strtoupper($classification); $sha256 = strtolower(trim($sha256));
        if ('' === trim($title) || !in_array($kind, self::KINDS, true) || !in_array($classification, self::CLASSIFICATIONS, true) || '' === trim($sourceReference) || 1 !== preg_match('/^[a-f0-9]{64}$/', $sha256) || (null !== $validFrom && null !== $expiresAt && $expiresAt < $validFrom)) {
            throw new \InvalidArgumentException('Preuve invalide : titre, type, classification, source, SHA-256 et validité sont requis.');
        }
        if ('APPROVED' === $this->status || 'SUPERSEDED' === $this->status) {
            throw new \LogicException('Une preuve approuvée est immuable ; créez une nouvelle version.');
        }
        $this->title = trim($title); $this->kind = $kind; $this->classification = $classification; $this->sourceReference = trim($sourceReference); $this->sha256 = $sha256; $this->validFrom = $validFrom; $this->expiresAt = $expiresAt; $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @param list<Requirement> $requirements
     * @param list<SecurityControl> $controls
     * @param list<ComplianceResult> $results
     * @param list<ActionPlan> $actions
     */
    public function link(array $requirements, array $controls, array $results, array $actions): void
    {
        foreach ([[$this->requirements, $requirements], [$this->controls, $controls], [$this->results, $results], [$this->actions, $actions]] as [$collection, $items]) {
            $collection->clear(); foreach ($items as $item) { $collection->add($item); }
        }
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function submit(): void { if ('DRAFT' !== $this->status) throw new \LogicException('Seule une preuve brouillon peut être soumise.'); $this->status = 'IN_REVIEW'; $this->updatedAt = new \DateTimeImmutable(); }
    public function approve(User $approver): void { if ('IN_REVIEW' !== $this->status || $approver === $this->owner || $approver->getOrganization() !== $this->organization) throw new \LogicException('Approbation de preuve interdite.'); $this->status = 'APPROVED'; $this->approvedBy = $approver; $this->approvedAt = $this->updatedAt = new \DateTimeImmutable(); }
    public function supersede(): void { if ('APPROVED' !== $this->status) throw new \LogicException('Seule une preuve approuvée peut être remplacée.'); $this->status = 'SUPERSEDED'; $this->updatedAt = new \DateTimeImmutable(); }
}
