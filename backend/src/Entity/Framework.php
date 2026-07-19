<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FrameworkRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FrameworkRepository::class)]
#[ORM\Table(name: 'frameworks')]
class Framework
{
    public const STATUSES = ['ACTIVE', 'INACTIVE', 'ARCHIVED'];
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\Column(length: 180)] private string $name;
    #[ORM\Column(length: 50)] private string $version;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $description = null;
    #[ORM\Column(length: 180, nullable: true)] private ?string $publisher = null;
    #[ORM\Column(length: 20)] private string $status = 'ACTIVE';
    /** @var Collection<int, Requirement> */
    #[ORM\OneToMany(mappedBy: 'framework', targetEntity: Requirement::class)] private Collection $requirements;
    public function __construct(string $name, string $version)
    {
        $this->name = $name;
        $this->version = $version;
        $this->requirements = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $value): self
    {
        $this->name = $value;

        return $this;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function setVersion(string $value): self
    {
        $this->version = $value;

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

    public function getPublisher(): ?string
    {
        return $this->publisher;
    }

    public function setPublisher(?string $value): self
    {
        $this->publisher = $value;

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

    /** @return Collection<int, Requirement> */
    public function getRequirements(): Collection
    {
        return $this->requirements;
    }
}
