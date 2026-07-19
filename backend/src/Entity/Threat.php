<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ThreatRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ThreatRepository::class)]
#[ORM\Table(name: 'threats')]
class Threat
{
    public const STATUSES = ['ACTIVE', 'INACTIVE', 'ARCHIVED'];
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\Column(length: 180)] private string $name;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $description = null;
    #[ORM\Column(length: 100)] private string $category;
    #[ORM\Column(length: 180, nullable: true)] private ?string $source = null;
    #[ORM\Column(length: 20)] private string $status = 'ACTIVE';
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

    public function getSource(): ?string
    {
        return $this->source;
    }

    public function setSource(?string $source): self
    {
        $this->source = $source;

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

    public function getOrganization(): Organization
    {
        return $this->organization;
    }
}
