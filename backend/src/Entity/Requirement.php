<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RequirementRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RequirementRepository::class)]
#[ORM\Table(name: 'requirements')]
#[ORM\UniqueConstraint(name: 'uniq_framework_reference', columns: ['framework_id', 'reference'])]
class Requirement
{
    public const STATUSES = ['ACTIVE', 'INACTIVE', 'ARCHIVED'];
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne(inversedBy: 'requirements')] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Framework $framework;
    #[ORM\Column(length: 100)] private string $reference;
    #[ORM\Column(length: 255)] private string $title;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $description = null;
    #[ORM\Column(length: 120)] private string $category;
    #[ORM\ManyToOne(targetEntity: Requirement::class)] #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')] private ?self $parentRequirement = null;
    #[ORM\Column(length: 20)] private string $status = 'ACTIVE';
    public function __construct(Framework $framework, string $reference, string $title, string $category)
    {
        $this->framework = $framework;
        $this->reference = $reference;
        $this->title = $title;
        $this->category = $category;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFramework(): Framework
    {
        return $this->framework;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function setReference(string $value): self
    {
        $this->reference = $value;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $value): self
    {
        $this->title = $value;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $value): self
    {
        $this->description = $value;

        return $this;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $value): self
    {
        $this->category = $value;

        return $this;
    }

    public function getParentRequirement(): ?self
    {
        return $this->parentRequirement;
    }

    public function setParentRequirement(?self $value): self
    {
        $this->parentRequirement = $value;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $value): self
    {
        $this->status = $value;

        return $this;
    }
}
