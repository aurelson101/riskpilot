<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RiskGovernancePolicyRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RiskGovernancePolicyRepository::class)]
#[ORM\Table(name: 'risk_governance_policies')]
#[ORM\UniqueConstraint(name: 'uniq_risk_policy_org_domain_family', columns: ['organization_id', 'domain', 'family'])]
class RiskGovernancePolicy
{
    public const METHODS = ['SIMPLIFIED', 'ISO_27005', 'EBIOS_RM'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Organization $organization;
    #[ORM\Column(length: 100)] private string $domain;
    #[ORM\Column(length: 100)] private string $family;
    #[ORM\Column] private int $appetiteScore;
    #[ORM\Column] private int $toleranceScore;
    #[ORM\Column] private int $capacityScore;
    #[ORM\Column(length: 20)] private string $method;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $rationale = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private User $owner;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    #[ORM\Column] private \DateTimeImmutable $updatedAt;
    public function __construct(Organization $organization, string $domain, string $family, User $owner)
    {
        $this->organization = $organization;
        $this->domain = trim($domain);
        $this->family = trim($family);
        $this->owner = $owner;
        $this->appetiteScore = 4;
        $this->toleranceScore = 9;
        $this->capacityScore = 16;
        $this->method = 'SIMPLIFIED';
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function getFamily(): string
    {
        return $this->family;
    }

    public function getAppetiteScore(): int
    {
        return $this->appetiteScore;
    }

    public function getToleranceScore(): int
    {
        return $this->toleranceScore;
    }

    public function getCapacityScore(): int
    {
        return $this->capacityScore;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getRationale(): ?string
    {
        return $this->rationale;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function update(string $domain, string $family, int $appetite, int $tolerance, int $capacity, string $method, ?string $rationale, User $owner): void
    {
        if ($appetite > $tolerance || $tolerance > $capacity) {
            throw new \InvalidArgumentException('L’appétence doit être inférieure ou égale à la tolérance, elle-même inférieure ou égale à la capacité.');
        }
        $this->domain = trim($domain);
        $this->family = trim($family);
        $this->appetiteScore = $appetite;
        $this->toleranceScore = $tolerance;
        $this->capacityScore = $capacity;
        $this->method = $method;
        $this->rationale = null === $rationale || '' === trim($rationale) ? null : trim($rationale);
        $this->owner = $owner;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function position(int $score): string
    {
        return $score <= $this->appetiteScore ? 'WITHIN_APPETITE' : ($score <= $this->toleranceScore ? 'TOLERATED' : ($score <= $this->capacityScore ? 'ABOVE_TOLERANCE' : 'ABOVE_CAPACITY'));
    }
}
