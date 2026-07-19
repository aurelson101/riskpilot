<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ComplianceAssessmentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ComplianceAssessmentRepository::class)]
#[ORM\Table(name: 'compliance_assessments')]
#[ORM\HasLifecycleCallbacks]
class ComplianceAssessment
{
    public const STATUSES = ['DRAFT', 'IN_PROGRESS', 'COMPLETED', 'ARCHIVED'];
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Organization $organization;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private Framework $framework;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private Scope $scope;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private User $assessor;
    #[ORM\Column] private \DateTimeImmutable $assessmentDate;
    #[ORM\Column(length: 20)] private string $status = 'DRAFT';
    #[ORM\Column] private float $globalScore = 0.0;
    /** @var Collection<int, ComplianceResult> */
    #[ORM\OneToMany(mappedBy: 'assessment', targetEntity: ComplianceResult::class, cascade: ['persist'], orphanRemoval: true)] private Collection $results;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    #[ORM\Column] private \DateTimeImmutable $updatedAt;
    public function __construct(Organization $organization, Framework $framework, Scope $scope, User $assessor, \DateTimeImmutable $date)
    {
        $this->organization = $organization;
        $this->framework = $framework;
        $this->scope = $scope;
        $this->assessor = $assessor;
        $this->assessmentDate = $date;
        $this->results = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
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

    public function setScope(Scope $value): self
    {
        $this->scope = $value;

        return $this;
    }

    public function getAssessor(): User
    {
        return $this->assessor;
    }

    public function setAssessor(User $value): self
    {
        $this->assessor = $value;

        return $this;
    }

    public function getAssessmentDate(): \DateTimeImmutable
    {
        return $this->assessmentDate;
    }

    public function setAssessmentDate(\DateTimeImmutable $value): self
    {
        $this->assessmentDate = $value;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $value): self
    {
        $this->status = $value;

        return $this;
    }

    public function getGlobalScore(): float
    {
        return $this->globalScore;
    }

    /** @return Collection<int, ComplianceResult> */
    public function getResults(): Collection
    {
        return $this->results;
    }

    public function addResult(ComplianceResult $result): void
    {
        if (!$this->results->contains($result)) {
            $this->results->add($result);
        }
    }

    public function recalculateScore(): void
    {
        $values = [];
        foreach ($this->results as $result) {
            $value = $result->scoreValue();
            if (null !== $value) {
                $values[] = $value;
            }
        }
        $this->globalScore = [] === $values ? 0.0 : round(array_sum($values) / count($values), 2);
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
