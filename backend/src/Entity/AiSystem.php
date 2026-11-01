<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AiSystemRepository;
use Doctrine\ORM\Mapping as ORM;

/** Registre des systèmes d'IA de l'organisation (ISO/IEC 42001 A.4.2, A.6.2.7). */
#[ORM\Entity(repositoryClass: AiSystemRepository::class)]
#[ORM\Table(name: 'ai_systems')]
class AiSystem
{
    /** Niveaux de risque inspirés du règlement européen sur l'IA ; la qualification juridique reste à valider. */
    public const RISK_LEVELS = ['MINIMAL', 'LIMITED', 'HIGH', 'PROHIBITED'];
    public const STATUSES = ['PLANNED', 'ACTIVE', 'SUSPENDED', 'RETIRED'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Organization $organization;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private User $owner;
    #[ORM\Column(length: 180)] private string $name;
    #[ORM\Column(type: 'text')] private string $purpose;
    #[ORM\Column(length: 120)] private string $provider;
    #[ORM\Column(length: 120, nullable: true)] private ?string $model = null;
    /** @var list<string> */ #[ORM\Column(type: 'json')] private array $dataCategories = [];
    #[ORM\Column(length: 20)] private string $riskLevel;
    #[ORM\Column(length: 20)] private string $status;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $humanOversight = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $reviewAt = null;
    #[ORM\Column(options: ['default' => false])] private bool $builtIn = false;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    #[ORM\Column] private \DateTimeImmutable $updatedAt;
    /** @param list<string> $dataCategories */
    public function __construct(Organization $organization, User $owner, string $name, string $purpose, string $provider, ?string $model, array $dataCategories, string $riskLevel, string $status, ?string $humanOversight, ?\DateTimeImmutable $reviewAt, bool $builtIn = false)
    {
        $this->organization = $organization;
        $this->builtIn = $builtIn;
        $this->createdAt = new \DateTimeImmutable();
        $this->update($owner, $name, $purpose, $provider, $model, $dataCategories, $riskLevel, $status, $humanOversight, $reviewAt);
    }

    /** @param list<string> $dataCategories */
    public function update(User $owner, string $name, string $purpose, string $provider, ?string $model, array $dataCategories, string $riskLevel, string $status, ?string $humanOversight, ?\DateTimeImmutable $reviewAt): void
    {
        if ('' === trim($name) || mb_strlen(trim($name)) > 180 || '' === trim($purpose) || '' === trim($provider) || mb_strlen(trim($provider)) > 120
            || !in_array($riskLevel, self::RISK_LEVELS, true) || !in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Système d’IA invalide.');
        }
        if ('PROHIBITED' === $riskLevel && 'ACTIVE' === $status) {
            throw new \InvalidArgumentException('Un système d’IA de risque inacceptable ne peut pas être actif.');
        }
        if ('HIGH' === $riskLevel && 'ACTIVE' === $status && '' === trim((string) $humanOversight)) {
            throw new \InvalidArgumentException('Un système à haut risque actif exige une description de la supervision humaine.');
        }
        $categories = array_values(array_unique(array_filter(array_map(static fn (mixed $value): string => mb_substr(trim((string) $value), 0, 80), $dataCategories), static fn (string $value): bool => '' !== $value)));
        $this->owner = $owner;
        $this->name = trim($name);
        $this->purpose = trim($purpose);
        $this->provider = trim($provider);
        $this->model = null === $model || '' === trim($model) ? null : mb_substr(trim($model), 0, 120);
        $this->dataCategories = array_slice($categories, 0, 30);
        $this->riskLevel = $riskLevel;
        $this->status = $status;
        $this->humanOversight = null === $humanOversight || '' === trim($humanOversight) ? null : trim($humanOversight);
        $this->reviewAt = $reviewAt;
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

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPurpose(): string
    {
        return $this->purpose;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    /** @return list<string> */
    public function getDataCategories(): array
    {
        return $this->dataCategories;
    }

    public function getRiskLevel(): string
    {
        return $this->riskLevel;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getHumanOversight(): ?string
    {
        return $this->humanOversight;
    }

    public function getReviewAt(): ?\DateTimeImmutable
    {
        return $this->reviewAt;
    }

    public function isBuiltIn(): bool
    {
        return $this->builtIn;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
