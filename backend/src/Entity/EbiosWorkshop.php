<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\EbiosWorkshopRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: EbiosWorkshopRepository::class)]
#[ORM\Table(name: 'ebios_workshops')]
#[ORM\UniqueConstraint(name: 'uniq_ebios_analysis_workshop', columns: ['analysis_id', 'workshop_number'])]
class EbiosWorkshop
{
    public const STATUSES = ['DRAFT', 'READY', 'VALIDATED'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private RiskAnalysis $analysis;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Organization $organization;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private User $owner;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')] private ?User $validatedBy = null;
    #[ORM\Column(name: 'workshop_number')] private int $number;
    /** @var array<string, mixed> */
    #[ORM\Column(type: 'json')] private array $payload = [];
    #[ORM\Column(length: 20)] private string $status = 'DRAFT';
    #[ORM\Column] private int $version = 0;
    #[ORM\Column] private \DateTimeImmutable $updatedAt;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $validatedAt = null;
    public function __construct(RiskAnalysis $analysis, User $owner, int $number)
    {
        if ('EBIOS_RM' !== $analysis->getMethod() || $number < 1 || $number > 5 || $analysis->getOrganization() !== $owner->getOrganization()) {
            throw new \InvalidArgumentException('Atelier EBIOS RM invalide.');
        }
        $this->analysis = $analysis;
        $this->organization = $analysis->getOrganization();
        $this->owner = $owner;
        $this->number = $number;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** @param array<string, mixed> $payload */
    public function update(array $payload, User $owner): void
    {
        if ('VALIDATED' === $this->status || $owner->getOrganization() !== $this->organization) {
            throw new \LogicException('Un atelier validé ou étranger ne peut pas être modifié.');
        }
        $this->payload = $payload;
        $this->owner = $owner;
        $this->status = 'READY';
        ++$this->version;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function validate(User $validator): void
    {
        if ('READY' !== $this->status || $validator === $this->owner || $validator->getOrganization() !== $this->organization) {
            throw new \LogicException('Validation indépendante invalide.');
        }
        $this->status = 'VALIDATED';
        $this->validatedBy = $validator;
        $this->validatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAnalysis(): RiskAnalysis
    {
        return $this->analysis;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getValidatedBy(): ?User
    {
        return $this->validatedBy;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    /** @return array<string, mixed> */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getValidatedAt(): ?\DateTimeImmutable
    {
        return $this->validatedAt;
    }
}
