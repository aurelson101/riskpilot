<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ExecutiveGovernanceRecordRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ExecutiveGovernanceRecordRepository::class)] #[ORM\Table(name: 'executive_governance_records')]
class ExecutiveGovernanceRecord
{
    public const TYPES = ['OBJECTIVE', 'INDICATOR', 'MANAGEMENT_REVIEW', 'FINANCIAL_SCENARIO', 'INVESTMENT_CASE'];
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Organization $organization;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private User $owner;
    #[ORM\Column(length: 30)] private string $type;
    #[ORM\Column(length: 220)] private string $title;
    /** @var array<string, mixed> */ #[ORM\Column(type: 'json')] private array $details;
    #[ORM\Column(length: 20)] private string $status;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $reviewAt;
    #[ORM\Column] private \DateTimeImmutable $updatedAt;
    /** @param array<string, mixed> $details */
    public function __construct(Organization $organization, User $owner, string $type, string $title, array $details, string $status, ?\DateTimeImmutable $reviewAt)
    {
        $this->organization = $organization;
        $this->owner = $owner;
        $this->type = $type;
        $this->update($title, $details, $status, $reviewAt, $owner);
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

    public function getType(): string
    {
        return $this->type;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    /** @return array<string, mixed> */
    public function getDetails(): array
    {
        return $this->details;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getReviewAt(): ?\DateTimeImmutable
    {
        return $this->reviewAt;
    }

    /** @param array<string, mixed> $details */
    public function update(string $title, array $details, string $status, ?\DateTimeImmutable $reviewAt, User $owner): void
    {
        if (!in_array($this->type, self::TYPES, true) || '' === trim($title) || !in_array($status, ['DRAFT', 'ACTIVE', 'ON_TRACK', 'AT_RISK', 'COMPLETED', 'APPROVED'], true)) {
            throw new \InvalidArgumentException('Élément de pilotage invalide.');
        } $rules = ['OBJECTIVE' => ['target', 'measure', 'deadline'], 'INDICATOR' => ['kind', 'value', 'target', 'warningThreshold', 'criticalThreshold', 'period'], 'MANAGEMENT_REVIEW' => ['date', 'participants', 'inputs', 'decisions', 'actions'], 'FINANCIAL_SCENARIO' => ['frequencyMin', 'frequencyMax', 'lossMin', 'lossMostLikely', 'lossMax', 'currency'], 'INVESTMENT_CASE' => ['cost', 'effortDays', 'expectedLossReduction', 'roi', 'scenario']];
        foreach ($rules[$this->type] as $key) {
            if (!array_key_exists($key, $details)) {
                throw new \InvalidArgumentException(sprintf('Le champ %s est requis pour %s.', $key, $this->type));
            }
        } if ('FINANCIAL_SCENARIO' === $this->type && ((float) $details['lossMin'] > (float) $details['lossMostLikely'] || (float) $details['lossMostLikely'] > (float) $details['lossMax'])) {
            throw new \InvalidArgumentException('La fourchette de pertes doit être ordonnée.');
        } $this->title = trim($title);
        $this->details = $details;
        $this->status = $status;
        $this->reviewAt = $reviewAt;
        $this->owner = $owner;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
