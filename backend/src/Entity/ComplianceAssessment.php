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
    public const TISAX_LEVELS = ['AL2', 'AL3'];
    public const TISAX_OBJECTIVES = ['INFO_HIGH', 'INFO_VERY_HIGH', 'PROTO_PARTS', 'PROTO_VEHICLES', 'TEST_VEHICLES', 'EVENTS', 'DATA', 'SPECIAL_DATA'];
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Organization $organization;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private Framework $framework;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private Scope $scope;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private User $assessor;
    #[ORM\Column] private \DateTimeImmutable $assessmentDate;
    #[ORM\Column(length: 20)] private string $status = 'DRAFT';
    #[ORM\Column] private float $globalScore = 0.0;
    #[ORM\Column(length: 3, nullable: true)] private ?string $tisaxLevel = null;
    /** @var list<string> */ #[ORM\Column(type: 'json')] private array $tisaxObjectives = [];
    #[ORM\Column(length: 255, nullable: true)] private ?string $siteScope = null;
    #[ORM\Column(length: 40, nullable: true)] private ?string $participantId = null;
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

    public function getTisaxLevel(): ?string
    {
        return $this->tisaxLevel;
    }

    /** @return list<string> */
    public function getTisaxObjectives(): array
    {
        return $this->tisaxObjectives;
    }

    public function getSiteScope(): ?string
    {
        return $this->siteScope;
    }

    public function getParticipantId(): ?string
    {
        return $this->participantId;
    }

    /**
     * Qualification TISAX : niveau d'évaluation, objectifs, périmètre et identifiant de participant ENX.
     *
     * @param list<string> $objectives
     */
    public function configureTisax(?string $level, array $objectives, ?string $siteScope, ?string $participantId): void
    {
        $objectives = array_values(array_unique($objectives));
        if ((null !== $level && !in_array($level, self::TISAX_LEVELS, true)) || [] !== array_diff($objectives, self::TISAX_OBJECTIVES)) {
            throw new \InvalidArgumentException('Niveau ou objectif TISAX invalide.');
        }
        if (null !== $participantId && 1 !== preg_match('/^[A-Za-z0-9-]{1,40}$/', $participantId)) {
            throw new \InvalidArgumentException('Identifiant de participant TISAX invalide.');
        }
        $this->tisaxLevel = $level;
        $this->tisaxObjectives = $objectives;
        $this->siteScope = null === $siteScope || '' === trim($siteScope) ? null : mb_substr(trim($siteScope), 0, 255);
        $this->participantId = $participantId;
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
