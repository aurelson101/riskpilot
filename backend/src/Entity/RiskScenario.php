<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RiskScenarioRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RiskScenarioRepository::class)]
#[ORM\Table(name: 'risk_scenarios')]
#[ORM\HasLifecycleCallbacks]
class RiskScenario
{
    public const TREATMENTS = ['REDUCE', 'ACCEPT', 'TRANSFER', 'AVOID'];
    public const STATUSES = ['DRAFT', 'IN_REVIEW', 'APPROVED', 'TREATMENT_IN_PROGRESS', 'ACCEPTED', 'CLOSED', 'ARCHIVED'];
    public const METHODS = ['SIMPLIFIED', 'ISO_27005', 'EBIOS_RM'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\Column(length: 255)] private string $title;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $description = null;
    #[ORM\Column(length: 100)] private string $family = 'GENERAL';
    #[ORM\Column(length: 20)] private string $analysisMethod = 'SIMPLIFIED';
    #[ORM\Column] private bool $strategic = false;
    /** @var array<string, string> */
    #[ORM\Column(type: 'json')] private array $methodData = [];
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Organization $organization;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Scope $scope;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Asset $asset;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Threat $threat;
    /** @var Collection<int, Vulnerability> */
    #[ORM\ManyToMany(targetEntity: Vulnerability::class)] #[ORM\JoinTable(name: 'risk_vulnerabilities')]
    private Collection $vulnerabilities;
    /** @var Collection<int, SecurityControl> */
    #[ORM\ManyToMany(targetEntity: SecurityControl::class)] #[ORM\JoinTable(name: 'risk_security_controls')]
    private Collection $currentControls;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private User $riskOwner;
    #[ORM\Column] private int $likelihood = 1;
    #[ORM\Column] private int $impact = 1;
    #[ORM\Column] private int $grossRiskScore = 1;
    #[ORM\Column] private int $currentLikelihood = 1;
    #[ORM\Column] private int $currentImpact = 1;
    #[ORM\Column] private int $currentRiskScore = 1;
    #[ORM\Column] private int $residualLikelihood = 1;
    #[ORM\Column] private int $residualImpact = 1;
    #[ORM\Column] private int $residualRiskScore = 1;
    #[ORM\Column(length: 20)] private string $treatmentDecision = 'REDUCE';
    #[ORM\Column(length: 30)] private string $status = 'DRAFT';
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $reviewDate = null;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    #[ORM\Column] private \DateTimeImmutable $updatedAt;
    public function __construct(string $title, Organization $organization, Scope $scope, Asset $asset, Threat $threat, User $riskOwner)
    {
        $this->title = $title;
        $this->organization = $organization;
        $this->scope = $scope;
        $this->asset = $asset;
        $this->threat = $threat;
        $this->riskOwner = $riskOwner;
        $this->vulnerabilities = new ArrayCollection();
        $this->currentControls = new ArrayCollection();
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

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getFamily(): string
    {
        return $this->family;
    }

    public function getAnalysisMethod(): string
    {
        return $this->analysisMethod;
    }

    public function isStrategic(): bool
    {
        return $this->strategic;
    }

    /** @return array<string, string> */
    public function getMethodData(): array
    {
        return $this->methodData;
    }

    /** @param array<string, string> $methodData */
    public function configureGovernance(string $family, string $method, bool $strategic, array $methodData = []): self
    {
        $this->family = trim($family);
        $this->analysisMethod = $method;
        $this->strategic = $strategic;
        $this->methodData = $methodData;

        return $this;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getScope(): Scope
    {
        return $this->scope;
    }

    public function setScope(Scope $scope): self
    {
        $this->scope = $scope;

        return $this;
    }

    public function getAsset(): Asset
    {
        return $this->asset;
    }

    public function setAsset(Asset $asset): self
    {
        $this->asset = $asset;

        return $this;
    }

    public function getThreat(): Threat
    {
        return $this->threat;
    }

    public function setThreat(Threat $threat): self
    {
        $this->threat = $threat;

        return $this;
    }

    public function getRiskOwner(): User
    {
        return $this->riskOwner;
    }

    public function setRiskOwner(User $owner): self
    {
        $this->riskOwner = $owner;

        return $this;
    }

    /** @return Collection<int, Vulnerability> */
    public function getVulnerabilities(): Collection
    {
        return $this->vulnerabilities;
    }

    /** @param iterable<Vulnerability> $items */
    public function replaceVulnerabilities(iterable $items): self
    {
        $this->vulnerabilities->clear();
        foreach ($items as $item) {
            $this->vulnerabilities->add($item);
        }

        return $this;
    }

    /** @return Collection<int, SecurityControl> */
    public function getCurrentControls(): Collection
    {
        return $this->currentControls;
    }

    /** @param iterable<SecurityControl> $items */
    public function replaceCurrentControls(iterable $items): self
    {
        $this->currentControls->clear();
        foreach ($items as $item) {
            $this->currentControls->add($item);
        }

        return $this;
    }

    public function getLikelihood(): int
    {
        return $this->likelihood;
    }

    public function getImpact(): int
    {
        return $this->impact;
    }

    public function getGrossRiskScore(): int
    {
        return $this->grossRiskScore;
    }

    public function getCurrentLikelihood(): int
    {
        return $this->currentLikelihood;
    }

    public function getCurrentImpact(): int
    {
        return $this->currentImpact;
    }

    public function getCurrentRiskScore(): int
    {
        return $this->currentRiskScore;
    }

    public function getResidualLikelihood(): int
    {
        return $this->residualLikelihood;
    }

    public function getResidualImpact(): int
    {
        return $this->residualImpact;
    }

    public function getResidualRiskScore(): int
    {
        return $this->residualRiskScore;
    }

    public function setEvaluations(int $likelihood, int $impact, int $gross, int $currentLikelihood, int $currentImpact, int $current, int $residualLikelihood, int $residualImpact, int $residual): self
    {
        $this->likelihood = $likelihood;
        $this->impact = $impact;
        $this->grossRiskScore = $gross;
        $this->currentLikelihood = $currentLikelihood;
        $this->currentImpact = $currentImpact;
        $this->currentRiskScore = $current;
        $this->residualLikelihood = $residualLikelihood;
        $this->residualImpact = $residualImpact;
        $this->residualRiskScore = $residual;

        return $this;
    }

    public function getTreatmentDecision(): string
    {
        return $this->treatmentDecision;
    }

    public function setTreatmentDecision(string $decision): self
    {
        $this->treatmentDecision = $decision;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getReviewDate(): ?\DateTimeImmutable
    {
        return $this->reviewDate;
    }

    public function setReviewDate(?\DateTimeImmutable $date): self
    {
        $this->reviewDate = $date;

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
