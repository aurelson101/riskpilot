<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ComplianceResultRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ComplianceResultRepository::class)]
#[ORM\Table(name: 'compliance_results')]
#[ORM\UniqueConstraint(name: 'uniq_assessment_requirement', columns: ['assessment_id', 'requirement_id'])]
class ComplianceResult
{
    public const STATUSES = ['COMPLIANT', 'PARTIAL', 'NON_COMPLIANT', 'NOT_APPLICABLE', 'NOT_ASSESSED'];
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne(inversedBy: 'results')] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private ComplianceAssessment $assessment;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private Requirement $requirement;
    #[ORM\Column] private int $maturityLevel = 0;
    #[ORM\Column(length: 30)] private string $complianceStatus = 'NOT_ASSESSED';
    #[ORM\Column(type: 'text', nullable: true)] private ?string $comment = null;
    /** @var list<string> */ #[ORM\Column(type: 'json')] private array $evidence = [];
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')] private ?ActionPlan $remediationAction = null;
    public function __construct(ComplianceAssessment $assessment, Requirement $requirement)
    {
        $this->assessment = $assessment;
        $this->requirement = $requirement;
        $assessment->addResult($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAssessment(): ComplianceAssessment
    {
        return $this->assessment;
    }

    public function getRequirement(): Requirement
    {
        return $this->requirement;
    }

    public function getMaturityLevel(): int
    {
        return $this->maturityLevel;
    }

    public function setMaturityLevel(int $value): self
    {
        $this->maturityLevel = $value;

        return $this;
    }

    public function getComplianceStatus(): string
    {
        return $this->complianceStatus;
    }

    public function setComplianceStatus(string $value): self
    {
        $this->complianceStatus = $value;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $value): self
    {
        $this->comment = $value;

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

    public function getRemediationAction(): ?ActionPlan
    {
        return $this->remediationAction;
    }

    public function setRemediationAction(?ActionPlan $value): self
    {
        $this->remediationAction = $value;

        return $this;
    }

    public function scoreValue(): ?float
    {
        return match ($this->complianceStatus) {
            'COMPLIANT' => 100.0, 'PARTIAL' => 50.0, 'NON_COMPLIANT' => 0.0, default => null,
        };
    }
}
