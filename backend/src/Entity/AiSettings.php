<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AiSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AiSettingsRepository::class)]
#[ORM\Table(name: 'ai_settings')]
class AiSettings
{
    public const PROVIDERS = ['MISTRAL', 'OPENAI', 'GEMINI'];
    public const DATA_POLICIES = ['MINIMAL', 'CONTEXTUAL'];
    /** Version de l'avis d'usage (DPA, transferts, sous-traitant) acquitté par l'administrateur. */
    public const TERMS_VERSION = '2026-11';
    public const DEFAULT_MONTHLY_TOKEN_BUDGET = 500000;
    public const DEFAULT_DAILY_CALL_LIMIT = 200;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private Organization $organization;

    #[ORM\Column(length: 20)] private string $provider = 'MISTRAL';
    #[ORM\Column(length: 255)] private string $baseUrl = 'https://api.mistral.ai/v1';
    #[ORM\Column(length: 120)] private string $model = 'mistral-large-latest';
    #[ORM\Column(type: 'text', nullable: true)] private ?string $encryptedApiKey = null;
    #[ORM\Column(length: 20)] private string $dataPolicy = 'MINIMAL';
    #[ORM\Column(type: 'text')] private string $systemPrompt = '';
    #[ORM\Column] private bool $enabled = false;
    #[ORM\Column(options: ['default' => self::DEFAULT_MONTHLY_TOKEN_BUDGET])] private int $monthlyTokenBudget = self::DEFAULT_MONTHLY_TOKEN_BUDGET;
    #[ORM\Column(options: ['default' => self::DEFAULT_DAILY_CALL_LIMIT])] private int $dailyCallLimit = self::DEFAULT_DAILY_CALL_LIMIT;
    #[ORM\Column(length: 20, nullable: true)] private ?string $termsVersion = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $termsAcknowledgedAt = null;
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $termsAcknowledgedBy = null;
    #[ORM\Column] private \DateTimeImmutable $updatedAt;
    public function __construct(Organization $organization)
    {
        $this->organization = $organization;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getEncryptedApiKey(): ?string
    {
        return $this->encryptedApiKey;
    }

    public function getDataPolicy(): string
    {
        return $this->dataPolicy;
    }

    public function getSystemPrompt(): string
    {
        return $this->systemPrompt;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function configure(string $provider, string $baseUrl, string $model, string $dataPolicy, string $systemPrompt, bool $enabled): void
    {
        $this->provider = $provider;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->model = $model;
        $this->dataPolicy = $dataPolicy;
        $this->systemPrompt = $systemPrompt;
        $this->enabled = $enabled;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getMonthlyTokenBudget(): int
    {
        return $this->monthlyTokenBudget;
    }

    public function getDailyCallLimit(): int
    {
        return $this->dailyCallLimit;
    }

    public function setLimits(int $monthlyTokenBudget, int $dailyCallLimit): void
    {
        $this->monthlyTokenBudget = $monthlyTokenBudget;
        $this->dailyCallLimit = $dailyCallLimit;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function termsAcknowledged(): bool
    {
        return self::TERMS_VERSION === $this->termsVersion;
    }

    public function getTermsAcknowledgedAt(): ?\DateTimeImmutable
    {
        return $this->termsAcknowledgedAt;
    }

    public function getTermsAcknowledgedBy(): ?User
    {
        return $this->termsAcknowledgedBy;
    }

    public function acknowledgeTerms(User $administrator): void
    {
        $this->termsVersion = self::TERMS_VERSION;
        $this->termsAcknowledgedAt = new \DateTimeImmutable();
        $this->termsAcknowledgedBy = $administrator;
    }

    public function setEncryptedApiKey(string $encryptedApiKey): void
    {
        $this->encryptedApiKey = $encryptedApiKey;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
