<?php

declare(strict_types=1);

namespace App\Entity;

use App\Application\AiCallMetrics;
use App\Repository\AiInteractionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trace ISO/IEC 42001 d'un appel au copilote : métadonnées, empreintes et décision humaine, jamais le contenu brut.
 */
#[ORM\Entity(repositoryClass: AiInteractionRepository::class)]
#[ORM\Table(name: 'ai_interactions')]
#[ORM\Index(name: 'idx_ai_interactions_usage', columns: ['organization_id', 'created_at'])]
class AiInteraction
{
    public const STATUSES = ['OK', 'ERROR', 'RATE_LIMITED', 'BUDGET_EXCEEDED'];
    public const DECISIONS = ['ACCEPTED', 'EDITED', 'REJECTED'];
    public const DRAFT_WORKFLOWS = ['RISK_DRAFT', 'COMPLIANCE_ACTION_DRAFT'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Organization $organization;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user;

    #[ORM\Column(length: 40)] private string $workflow;
    #[ORM\Column(length: 20)] private string $provider;
    #[ORM\Column(length: 120)] private string $requestedModel;
    #[ORM\Column(length: 120, nullable: true)] private ?string $returnedModel = null;
    #[ORM\Column(length: 20)] private string $promptVersion;
    #[ORM\Column(length: 20)] private string $dataPolicy;
    #[ORM\Column(length: 64)] private string $inputHash;
    #[ORM\Column(length: 64, nullable: true)] private ?string $outputHash = null;
    #[ORM\Column] private int $inputTokens = 0;
    #[ORM\Column] private int $outputTokens = 0;
    #[ORM\Column] private int $latencyMs = 0;
    #[ORM\Column] private int $attempts = 0;
    #[ORM\Column(length: 20)] private string $status;
    #[ORM\Column(length: 60, nullable: true)] private ?string $errorCode = null;
    #[ORM\Column(length: 20, nullable: true)] private ?string $consentVersion;
    #[ORM\Column(length: 20, nullable: true)] private ?string $decision = null;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $decisionComment = null;
    #[ORM\Column(length: 30, nullable: true)] private ?string $targetType = null;
    #[ORM\Column(nullable: true)] private ?int $targetId = null;
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $decidedBy = null;

    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $decidedAt = null;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    public function __construct(Organization $organization, User $user, string $workflow, AiSettings $settings, string $dataPolicy, string $promptVersion, string $inputHash, ?string $consentVersion)
    {
        $this->organization = $organization;
        $this->user = $user;
        $this->workflow = $workflow;
        $this->provider = $settings->getProvider();
        $this->requestedModel = $settings->getModel();
        $this->dataPolicy = $dataPolicy;
        $this->promptVersion = $promptVersion;
        $this->inputHash = $inputHash;
        $this->consentVersion = $consentVersion;
        $this->status = 'ERROR';
        $this->createdAt = new \DateTimeImmutable();
    }

    public function complete(string $status, ?string $outputHash, ?string $errorCode, int $latencyMs, ?AiCallMetrics $metrics): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Unknown AI interaction status.');
        }
        $this->status = $status;
        $this->outputHash = $outputHash;
        $this->errorCode = null === $errorCode ? null : mb_substr($errorCode, 0, 60);
        $this->latencyMs = max(0, $latencyMs);
        if (null !== $metrics) {
            $this->returnedModel = null === $metrics->returnedModel ? null : mb_substr($metrics->returnedModel, 0, 120);
            $this->inputTokens = max(0, $metrics->inputTokens);
            $this->outputTokens = max(0, $metrics->outputTokens);
            $this->attempts = max(0, $metrics->attempts);
        }
    }

    public function decide(User $actor, string $decision, ?string $comment, ?string $targetType, ?int $targetId): void
    {
        if (!in_array($decision, self::DECISIONS, true)) {
            throw new \InvalidArgumentException('Unknown AI decision.');
        }
        $this->decision = $decision;
        $this->decisionComment = $comment;
        $this->targetType = $targetType;
        $this->targetId = $targetId;
        $this->decidedBy = $actor;
        $this->decidedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getWorkflow(): string
    {
        return $this->workflow;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getRequestedModel(): string
    {
        return $this->requestedModel;
    }

    public function getReturnedModel(): ?string
    {
        return $this->returnedModel;
    }

    public function getPromptVersion(): string
    {
        return $this->promptVersion;
    }

    public function getDataPolicy(): string
    {
        return $this->dataPolicy;
    }

    public function getInputHash(): string
    {
        return $this->inputHash;
    }

    public function getOutputHash(): ?string
    {
        return $this->outputHash;
    }

    public function getInputTokens(): int
    {
        return $this->inputTokens;
    }

    public function getOutputTokens(): int
    {
        return $this->outputTokens;
    }

    public function getLatencyMs(): int
    {
        return $this->latencyMs;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    public function getConsentVersion(): ?string
    {
        return $this->consentVersion;
    }

    public function getDecision(): ?string
    {
        return $this->decision;
    }

    public function getDecisionComment(): ?string
    {
        return $this->decisionComment;
    }

    public function getTargetType(): ?string
    {
        return $this->targetType;
    }

    public function getTargetId(): ?int
    {
        return $this->targetId;
    }

    public function getDecidedBy(): ?User
    {
        return $this->decidedBy;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
