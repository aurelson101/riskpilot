<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AiSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AiSettingsRepository::class)]
#[ORM\Table(name: 'ai_settings')]
class AiSettings
{
    public const PROVIDERS = ['MISTRAL', 'OPENAI', 'GEMINI', 'CUSTOM'];
    public const DATA_POLICIES = ['MINIMAL', 'CONTEXTUAL'];

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

    public function setEncryptedApiKey(string $encryptedApiKey): void
    {
        $this->encryptedApiKey = $encryptedApiKey;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
