<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AssetRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AssetRepository::class)]
#[ORM\Table(name: 'assets')]
#[ORM\HasLifecycleCallbacks]
class Asset
{
    public const TYPES = ['BUSINESS_PROCESS', 'DATA', 'APPLICATION', 'SERVER', 'NETWORK', 'WORKSTATION', 'CLOUD_SERVICE', 'SUPPLIER', 'FACILITY', 'OTHER'];
    public const STATUSES = ['ACTIVE', 'INACTIVE', 'ARCHIVED'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;
    #[ORM\Column(length: 180)] private string $name;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $description = null;
    #[ORM\Column(length: 30)] private string $type;
    #[ORM\Column] private int $criticality = 1;
    #[ORM\Column] private int $confidentiality = 1;
    #[ORM\Column] private int $integrity = 1;
    #[ORM\Column] private int $availability = 1;
    #[ORM\ManyToOne] #[ORM\JoinColumn(onDelete: 'SET NULL')] private ?User $owner = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Scope $scope;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Organization $organization;
    #[ORM\Column(length: 20)] private string $status = 'ACTIVE';
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    #[ORM\Column] private \DateTimeImmutable $updatedAt;
    /** @var Collection<int, self> */
    #[ORM\ManyToMany(targetEntity: self::class)]
    #[ORM\JoinTable(name: 'asset_relations')]
    private Collection $relatedAssets;

    public function __construct(string $name, string $type, Scope $scope, Organization $organization)
    {
        $this->name = $name;
        $this->type = $type;
        $this->scope = $scope;
        $this->organization = $organization;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
        $this->relatedAssets = new ArrayCollection();
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

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getCriticality(): int
    {
        return $this->criticality;
    }

    public function setCriticality(int $value): self
    {
        $this->criticality = $value;

        return $this;
    }

    public function getConfidentiality(): int
    {
        return $this->confidentiality;
    }

    public function setConfidentiality(int $value): self
    {
        $this->confidentiality = $value;

        return $this;
    }

    public function getIntegrity(): int
    {
        return $this->integrity;
    }

    public function setIntegrity(int $value): self
    {
        $this->integrity = $value;

        return $this;
    }

    public function getAvailability(): int
    {
        return $this->availability;
    }

    public function setAvailability(int $value): self
    {
        $this->availability = $value;

        return $this;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): self
    {
        $this->owner = $owner;

        return $this;
    }

    public function getScope(): Scope
    {
        return $this->scope;
    }

    public function setScope(Scope $scope): self
    {
        $this->scope = $scope;

        return $this;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
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

    /** @return Collection<int, self> */
    public function getRelatedAssets(): Collection
    {
        return $this->relatedAssets;
    }

    /** @param iterable<self> $assets */
    public function replaceRelatedAssets(iterable $assets): self
    {
        $this->relatedAssets->clear();
        foreach ($assets as $asset) {
            $this->relatedAssets->add($asset);
        }

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

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
