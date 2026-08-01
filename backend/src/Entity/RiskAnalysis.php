<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RiskAnalysisRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RiskAnalysisRepository::class)]
#[ORM\Table(name: 'risk_analyses')]
#[ORM\UniqueConstraint(name: 'uniq_analysis_version', columns: ['organization_id', 'analysis_key', 'version'])]
#[ORM\Index(columns: ['organization_id', 'status', 'method'], name: 'idx_analysis_tenant')]
class RiskAnalysis
{
    public const METHODS = ['EBIOS_RM', 'ISO_27005', 'SIMPLIFIED'];
    public const STATUSES = ['DRAFT', 'IN_REVIEW', 'APPROVED', 'ARCHIVED'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Organization $organization;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private User $createdBy;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')] private ?User $approvedBy = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')] private ?Scope $scope = null;
    #[ORM\ManyToOne(targetEntity: self::class)] #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')] private ?self $supersedes = null;
    #[ORM\Column(name: 'analysis_key', length: 100)] private string $key;
    #[ORM\Column] private int $version;
    #[ORM\Column(length: 30)] private string $method;
    #[ORM\Column(length: 180)] private string $title;
    /** @var array<array-key, mixed> */ #[ORM\Column(type: 'json')] private array $objectives;
    /** @var array<array-key, mixed> */ #[ORM\Column(type: 'json')] private array $team;
    /** @var array<array-key, mixed> */ #[ORM\Column(type: 'json')] private array $milestones;
    /** @var array<array-key, mixed> */ #[ORM\Column(type: 'json')] private array $scenarioIds;
    /** @var array<array-key, mixed> */ #[ORM\Column(type: 'json')] private array $scale;
    /** @var array<array-key, mixed> */ #[ORM\Column(type: 'json')] private array $baseline = [];
    /** @var array<array-key, mixed> */ #[ORM\Column(type: 'json')] private array $qualityFindings = [];
    #[ORM\Column] private int $completeness = 0;
    #[ORM\Column(length: 20)] private string $status = 'DRAFT';
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $approvedAt = null;
    /** @param array<array-key, mixed> $data */
    public function __construct(Organization $organization, User $createdBy, string $key, int $version, string $method, string $title, array $data = [], ?self $supersedes = null)
    {
        if (!in_array($method, self::METHODS, true) || '' === trim($key) || '' === trim($title) || $version < 1) {
            throw new \InvalidArgumentException('Analyse de risques invalide.');
        }
        $this->organization = $organization;
        $this->createdBy = $createdBy;
        $this->key = strtolower(trim($key));
        $this->version = $version;
        $this->method = $method;
        $this->title = trim($title);
        $this->supersedes = $supersedes;
        $this->objectives = (array) ($data['objectives'] ?? []);
        $this->team = (array) ($data['team'] ?? []);
        $this->milestones = (array) ($data['milestones'] ?? []);
        $this->scenarioIds = array_values(array_map('intval', (array) ($data['scenarioIds'] ?? [])));
        $this->scale = (array) ($data['scale'] ?? self::defaultScale());
        $this->createdAt = new \DateTimeImmutable();
    }

    /** @return array<array-key, mixed> */
    public static function defaultScale(): array
    {
        return ['version' => '1.0', 'dimensions' => ['likelihood', 'impact'], 'thresholds' => [4, 9, 16, 25], 'rounding' => 'NEAREST', 'colors' => ['#2e7d32', '#ed6c02', '#d32f2f']];
    }

    /** @param array<array-key, mixed> $data */
    public function configure(?Scope $scope, array $data): void
    {
        if ('DRAFT' !== $this->status) {
            throw new \LogicException('Une analyse figée ne peut plus être modifiée.');
        } $this->scope = $scope;
        $this->objectives = (array) ($data['objectives'] ?? $this->objectives);
        $this->team = (array) ($data['team'] ?? $this->team);
        $this->milestones = (array) ($data['milestones'] ?? $this->milestones);
        $this->scenarioIds = array_values(array_map('intval', (array) ($data['scenarioIds'] ?? $this->scenarioIds)));
        $this->scale = (array) ($data['scale'] ?? $this->scale);
    }

    /** @param array<array-key, mixed> $findings */
    public function review(array $findings, int $completeness): void
    {
        if ('DRAFT' !== $this->status) {
            throw new \LogicException('Transition invalide.');
        } $this->qualityFindings = $findings;
        $this->completeness = max(0, min(100, $completeness));
        if ([] !== $findings) {
            throw new \LogicException('Les règles qualité bloquantes ne sont pas satisfaites.');
        } $this->status = 'IN_REVIEW';
    }

    /** @param array<array-key, mixed> $baseline */
    public function approve(User $approver, array $baseline): void
    {
        if ('IN_REVIEW' !== $this->status || $approver === $this->createdBy || $approver->getOrganization() !== $this->organization) {
            throw new \LogicException('Approbation indépendante invalide.');
        } $this->status = 'APPROVED';
        $this->approvedBy = $approver;
        $this->approvedAt = new \DateTimeImmutable();
        $this->baseline = $baseline;
        $this->completeness = 100;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function getApprovedBy(): ?User
    {
        return $this->approvedBy;
    }

    public function getScope(): ?Scope
    {
        return $this->scope;
    }

    public function getSupersedes(): ?self
    {
        return $this->supersedes;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    /** @return array<array-key, mixed> */
    public function getObjectives(): array
    {
        return $this->objectives;
    }

    /** @return array<array-key, mixed> */
    public function getTeam(): array
    {
        return $this->team;
    }

    /** @return array<array-key, mixed> */
    public function getMilestones(): array
    {
        return $this->milestones;
    }

    /** @return array<array-key, mixed> */
    public function getScenarioIds(): array
    {
        return $this->scenarioIds;
    }

    /** @return array<array-key, mixed> */
    public function getScale(): array
    {
        return $this->scale;
    }

    /** @return array<array-key, mixed> */
    public function getBaseline(): array
    {
        return $this->baseline;
    }

    /** @return array<array-key, mixed> */
    public function getQualityFindings(): array
    {
        return $this->qualityFindings;
    }

    public function getCompleteness(): int
    {
        return $this->completeness;
    }

    public function getStatus(): string
    {
        return $this->status;
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
