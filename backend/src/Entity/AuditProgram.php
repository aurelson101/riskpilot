<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuditProgramRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AuditProgramRepository::class)]
#[ORM\Table(name: 'audit_programs')]
#[ORM\UniqueConstraint(name: 'uniq_audit_program_org_year', columns: ['organization_id', 'year'])]
class AuditProgram
{
    public const STATUSES = ['DRAFT', 'APPROVED', 'ACTIVE', 'COMPLETED', 'ARCHIVED'];
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Organization $organization;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private User $owner;
    #[ORM\Column] private int $year;
    #[ORM\Column(length: 200)] private string $title;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $objectives = null;
    #[ORM\Column(length: 20)] private string $status = 'DRAFT';
    /** @var Collection<int, AuditEngagement> */
    #[ORM\OneToMany(mappedBy: 'program', targetEntity: AuditEngagement::class, cascade: ['persist'], orphanRemoval: true)] private Collection $engagements;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    #[ORM\Column] private \DateTimeImmutable $updatedAt;
    public function __construct(Organization $organization, User $owner, int $year, string $title)
    {
        if ($year < 2000 || '' === trim($title)) {
            throw new \InvalidArgumentException('Programme d’audit invalide.');
        } $this->organization = $organization;
        $this->owner = $owner;
        $this->year = $year;
        $this->title = trim($title);
        $this->engagements = new ArrayCollection();
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

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getYear(): int
    {
        return $this->year;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getObjectives(): ?string
    {
        return $this->objectives;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /** @return Collection<int, AuditEngagement> */
    public function getEngagements(): Collection
    {
        return $this->engagements;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function update(string $title, ?string $objectives, string $status, User $owner): void
    {
        if (!in_array($status, self::STATUSES, true) || '' === trim($title)) {
            throw new \InvalidArgumentException('Programme d’audit invalide.');
        } $this->title = trim($title);
        $this->objectives = null === $objectives || '' === trim($objectives) ? null : trim($objectives);
        $this->status = $status;
        $this->owner = $owner;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function addEngagement(AuditEngagement $engagement): void
    {
        if (!$this->engagements->contains($engagement)) {
            $this->engagements->add($engagement);
        }
    }
}
