<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StatementOfApplicabilityRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StatementOfApplicabilityRepository::class)]
#[ORM\Table(name: 'statements_of_applicability')]
#[ORM\UniqueConstraint(name: 'uniq_soa_org_framework_scope_version', columns: ['organization_id', 'framework_id', 'scope_id', 'version_number'])]
class StatementOfApplicability
{
    public const STATUSES = ['DRAFT', 'IN_REVIEW', 'APPROVED', 'SUPERSEDED'];
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Organization $organization;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private Framework $framework;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private Scope $scope;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private User $owner;
    #[ORM\ManyToOne(targetEntity: self::class)] #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')] private ?self $previousVersion = null;
    #[ORM\Column(length: 200)] private string $title;
    #[ORM\Column] private int $versionNumber;
    #[ORM\Column(length: 20)] private string $status = 'DRAFT';
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')] private ?User $approvedBy = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $approvedAt = null;
    /** @var Collection<int, StatementOfApplicabilityItem> */
    #[ORM\OneToMany(mappedBy: 'statement', targetEntity: StatementOfApplicabilityItem::class, cascade: ['persist'], orphanRemoval: true)]
    private Collection $items;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    #[ORM\Column] private \DateTimeImmutable $updatedAt;
    public function __construct(Organization $organization, Framework $framework, Scope $scope, User $owner, string $title, int $versionNumber = 1, ?self $previousVersion = null)
    {
        $this->organization = $organization;
        $this->framework = $framework;
        $this->scope = $scope;
        $this->owner = $owner;
        $this->title = trim($title);
        $this->versionNumber = $versionNumber;
        $this->previousVersion = $previousVersion;
        $this->items = new ArrayCollection();
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

    public function getFramework(): Framework
    {
        return $this->framework;
    }

    public function getScope(): Scope
    {
        return $this->scope;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getPreviousVersion(): ?self
    {
        return $this->previousVersion;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getVersionNumber(): int
    {
        return $this->versionNumber;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getApprovedBy(): ?User
    {
        return $this->approvedBy;
    }

    public function getApprovedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }

    /** @return Collection<int, StatementOfApplicabilityItem> */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setStatus(string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Statut de SoA invalide.');
        } if ('APPROVED' === $this->status) {
            throw new \LogicException('Une SoA approuvée est immuable. Créez une nouvelle version.');
        } $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function approve(User $approver): void
    {
        if ('APPROVED' === $this->status) {
            throw new \LogicException('Cette SoA est déjà approuvée.');
        } $this->status = 'APPROVED';
        $this->approvedBy = $approver;
        $this->approvedAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function supersede(): void
    {
        if ('APPROVED' === $this->status) {
            $this->status = 'SUPERSEDED';
            $this->updatedAt = new \DateTimeImmutable();
        }
    }

    public function addItem(StatementOfApplicabilityItem $item): void
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
        }
    }
}
