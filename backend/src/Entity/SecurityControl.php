<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SecurityControlRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SecurityControlRepository::class)]
#[ORM\Table(name: 'security_controls')]
class SecurityControl
{
    public const STATUSES = ['NOT_IMPLEMENTED', 'PLANNED', 'PARTIAL', 'IMPLEMENTED'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\Column(length: 180)] private string $name;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $description = null;
    #[ORM\Column(length: 100)] private string $category;
    #[ORM\Column] private int $effectiveness = 0;
    #[ORM\Column(length: 30)] private string $implementationStatus = 'NOT_IMPLEMENTED';
    #[ORM\ManyToOne] #[ORM\JoinColumn(onDelete: 'SET NULL')] private ?User $owner = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Organization $organization;
    public function __construct(string $name, string $category, Organization $organization)
    {
        $this->name = $name;
        $this->category = $category;
        $this->organization = $organization;
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

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getEffectiveness(): int
    {
        return $this->effectiveness;
    }

    public function setEffectiveness(int $effectiveness): self
    {
        $this->effectiveness = $effectiveness;

        return $this;
    }

    public function getImplementationStatus(): string
    {
        return $this->implementationStatus;
    }

    public function setImplementationStatus(string $status): self
    {
        $this->implementationStatus = $status;

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

    public function getOrganization(): Organization
    {
        return $this->organization;
    }
}
