<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OrganizationRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrganizationRepository::class)]
#[ORM\Table(name: 'organizations')]
#[ORM\HasLifecycleCallbacks]
class Organization
{
    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_INACTIVE = 'INACTIVE';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_ACTIVE;

    /** @var array{lowMax: int, moderateMax: int, highMax: int, criticalMax: int} */
    #[ORM\Column(type: 'json')]
    private array $riskThresholds = ['lowMax' => 4, 'moderateMax' => 9, 'highMax' => 16, 'criticalMax' => 25];

    /** @var array<string, list<string>> */
    #[ORM\Column(type: 'json')]
    private array $rolePermissions = [];

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $nis2EntityType = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $nis2Sector = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $nis2Authority = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, User> */
    #[ORM\OneToMany(targetEntity: User::class, mappedBy: 'organization')]
    private Collection $users;

    public const NIS2_ENTITY_TYPES = ['ESSENTIAL', 'IMPORTANT', 'NOT_IN_SCOPE'];

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->users = new ArrayCollection();
    }

    public function getNis2EntityType(): ?string
    {
        return $this->nis2EntityType;
    }

    public function getNis2Sector(): ?string
    {
        return $this->nis2Sector;
    }

    public function getNis2Authority(): ?string
    {
        return $this->nis2Authority;
    }

    public function configureNis2(?string $entityType, ?string $sector, ?string $authority): void
    {
        if (null !== $entityType && !in_array($entityType, self::NIS2_ENTITY_TYPES, true)) {
            throw new \InvalidArgumentException('Type d’entité NIS2 invalide.');
        }
        $clean = static fn (?string $value, int $max): ?string => null === $value || '' === trim($value) ? null : mb_substr(trim($value), 0, $max);
        $this->nis2EntityType = $entityType;
        $this->nis2Sector = $clean($sector, 120);
        $this->nis2Authority = $clean($authority, 180);
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    /** @return array{lowMax: int, moderateMax: int, highMax: int, criticalMax: int} */
    public function getRiskThresholds(): array
    {
        return $this->riskThresholds;
    }

    /** @param array{lowMax: int, moderateMax: int, highMax: int, criticalMax: int} $thresholds */
    public function setRiskThresholds(array $thresholds): self
    {
        $this->riskThresholds = $thresholds;

        return $this;
    }

    /** @return array<string, list<string>> */
    public function getRolePermissions(): array
    {
        return $this->rolePermissions;
    }

    /** @param array<string, list<string>> $permissions */
    public function setRolePermissions(array $permissions): self
    {
        $this->rolePermissions = $permissions;

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

    /** @return Collection<int, User> */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
