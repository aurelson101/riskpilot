<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RequirementMappingRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RequirementMappingRepository::class)]
#[ORM\Table(name: 'requirement_mappings')]
#[ORM\UniqueConstraint(name: 'uniq_requirement_mapping_org_pair', columns: ['organization_id', 'source_requirement_id', 'target_requirement_id'])]
class RequirementMapping
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Organization $organization;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Requirement $sourceRequirement;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Requirement $targetRequirement;
    #[ORM\Column] private int $coveragePercent;
    #[ORM\Column] private bool $inheritEvidence;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $rationale = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private User $createdBy;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    public function __construct(Organization $organization, Requirement $source, Requirement $target, int $coveragePercent, bool $inheritEvidence, User $createdBy, ?string $rationale)
    {
        if ($source === $target || $coveragePercent < 1 || $coveragePercent > 100) {
            throw new \InvalidArgumentException('Correspondance d’exigences invalide.');
        } $this->organization = $organization;
        $this->sourceRequirement = $source;
        $this->targetRequirement = $target;
        $this->coveragePercent = $coveragePercent;
        $this->inheritEvidence = $inheritEvidence;
        $this->createdBy = $createdBy;
        $this->rationale = null === $rationale || '' === trim($rationale) ? null : trim($rationale);
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getSourceRequirement(): Requirement
    {
        return $this->sourceRequirement;
    }

    public function getTargetRequirement(): Requirement
    {
        return $this->targetRequirement;
    }

    public function getCoveragePercent(): int
    {
        return $this->coveragePercent;
    }

    public function doesInheritEvidence(): bool
    {
        return $this->inheritEvidence;
    }

    public function getRationale(): ?string
    {
        return $this->rationale;
    }

    public function getCreatedBy(): User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
