<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ActionPlanRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ActionPlanRepository::class)]
#[ORM\Table(name: 'action_plans')]
#[ORM\HasLifecycleCallbacks]
class ActionPlan
{
    public const PRIORITIES = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];
    public const STATUSES = ['OPEN', 'PLANNED', 'IN_PROGRESS', 'BLOCKED', 'COMPLETED', 'CANCELLED'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\Column(length: 255)] private string $title;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $description = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Organization $organization;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private RiskScenario $relatedRisk;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')] private ?SecurityControl $relatedControl = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private User $owner;
    #[ORM\Column(length: 20)] private string $priority = 'MEDIUM';
    #[ORM\Column(length: 30)] private string $status = 'OPEN';
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $startDate = null;
    #[ORM\Column] private \DateTimeImmutable $dueDate;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $completionDate = null;
    #[ORM\Column] private int $progress = 0;
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)] private ?string $estimatedCost = null;
    #[ORM\Column(type: 'decimal', precision: 8, scale: 2, nullable: true)] private ?string $estimatedEffortDays = null;
    #[ORM\Column(type: 'decimal', precision: 12, scale: 2, nullable: true)] private ?string $actualCost = null;
    #[ORM\Column(nullable: true)] private ?int $expectedRiskReduction = null;
    /** @var list<string> */
    #[ORM\Column(type: 'json')] private array $evidence = [];
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    #[ORM\Column] private \DateTimeImmutable $updatedAt;
    public function __construct(string $title, Organization $organization, RiskScenario $risk, User $owner, \DateTimeImmutable $dueDate)
    {
        $this->title = $title;
        $this->organization = $organization;
        $this->relatedRisk = $risk;
        $this->owner = $owner;
        $this->dueDate = $dueDate;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $value): self
    {
        $this->title = $value;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $value): self
    {
        $this->description = $value;

        return $this;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getRelatedRisk(): RiskScenario
    {
        return $this->relatedRisk;
    }

    public function setRelatedRisk(RiskScenario $value): self
    {
        $this->relatedRisk = $value;

        return $this;
    }

    public function getRelatedControl(): ?SecurityControl
    {
        return $this->relatedControl;
    }

    public function setRelatedControl(?SecurityControl $value): self
    {
        $this->relatedControl = $value;

        return $this;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function setOwner(User $value): self
    {
        $this->owner = $value;

        return $this;
    }

    public function getPriority(): string
    {
        return $this->priority;
    }

    public function setPriority(string $value): self
    {
        $this->priority = $value;

        return $this;
    }

    public function getStoredStatus(): string
    {
        return $this->status;
    }

    public function getStatus(): string
    {
        if (!in_array($this->status, ['COMPLETED', 'CANCELLED'], true) && $this->dueDate < new \DateTimeImmutable('today')) {
            return 'OVERDUE';
        }

        return $this->status;
    }

    public function setStatus(string $value): self
    {
        $this->status = $value;
        if ('COMPLETED' === $value) {
            $this->progress = 100;
            $this->completionDate ??= new \DateTimeImmutable();
        }

        return $this;
    }

    public function getStartDate(): ?\DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(?\DateTimeImmutable $value): self
    {
        $this->startDate = $value;

        return $this;
    }

    public function getDueDate(): \DateTimeImmutable
    {
        return $this->dueDate;
    }

    public function setDueDate(\DateTimeImmutable $value): self
    {
        $this->dueDate = $value;

        return $this;
    }

    public function getCompletionDate(): ?\DateTimeImmutable
    {
        return $this->completionDate;
    }

    public function setCompletionDate(?\DateTimeImmutable $value): self
    {
        $this->completionDate = $value;

        return $this;
    }

    public function getProgress(): int
    {
        return $this->progress;
    }

    public function setProgress(int $value): self
    {
        $this->progress = $value;

        return $this;
    }

    public function getEstimatedCost(): ?string
    {
        return $this->estimatedCost;
    }

    public function setEstimatedCost(?string $value): self
    {
        $this->estimatedCost = $value;

        return $this;
    }

    public function getActualCost(): ?string
    {
        return $this->actualCost;
    }

    public function getEstimatedEffortDays(): ?string
    {
        return $this->estimatedEffortDays;
    }

    public function setEstimatedEffortDays(?string $value): self
    {
        $this->estimatedEffortDays = $value;

        return $this;
    }

    public function setActualCost(?string $value): self
    {
        $this->actualCost = $value;

        return $this;
    }

    public function getExpectedRiskReduction(): ?int
    {
        return $this->expectedRiskReduction;
    }

    public function setExpectedRiskReduction(?int $value): self
    {
        $this->expectedRiskReduction = $value;

        return $this;
    }

    /** @return list<string> */
    public function getEvidence(): array
    {
        return $this->evidence;
    }

    /** @param list<string> $value */
    public function setEvidence(array $value): self
    {
        $this->evidence = $value;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
