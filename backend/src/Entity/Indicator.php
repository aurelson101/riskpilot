<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IndicatorRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: IndicatorRepository::class)]
#[ORM\Table(name: 'indicators')]
#[ORM\UniqueConstraint(name: 'uniq_indicator_org_code', columns: ['organization_id', 'code'])]
class Indicator
{
    public const KINDS = ['KPI', 'KRI'];
    public const FREQUENCIES = ['DAILY', 'WEEKLY', 'MONTHLY', 'QUARTERLY', 'YEARLY', 'ON_DEMAND'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Organization $organization;
    #[ORM\Column(length: 80)] private string $code;
    #[ORM\Column(length: 180)] private string $name;
    #[ORM\Column(length: 10)] private string $kind;
    #[ORM\Column(length: 40)] private string $unit;
    #[ORM\Column(length: 20)] private string $frequency;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $formula = null;
    #[ORM\Column(length: 255, nullable: true)] private ?string $source = null;
    #[ORM\Column(type: 'decimal', precision: 20, scale: 6, nullable: true)] private ?string $target = null;
    /** @var array<string, int|float|string> */
    #[ORM\Column(type: 'json')] private array $thresholds = [];
    #[ORM\Column] private bool $active = true;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    /** @var Collection<int, IndicatorValue> */
    #[ORM\OneToMany(mappedBy: 'indicator', targetEntity: IndicatorValue::class, cascade: ['remove'], orphanRemoval: true)]
    private Collection $values;

    public function __construct(Organization $organization, string $code, string $name, string $kind, string $unit, string $frequency)
    {
        $this->organization = $organization;
        $this->code = trim($code);
        $this->name = trim($name);
        $this->kind = $kind;
        $this->unit = trim($unit);
        $this->frequency = $frequency;
        $this->createdAt = new \DateTimeImmutable();
        $this->values = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getOrganization(): Organization { return $this->organization; }
    public function getCode(): string { return $this->code; }
    public function getName(): string { return $this->name; }
    public function getKind(): string { return $this->kind; }
    public function getUnit(): string { return $this->unit; }
    public function getFrequency(): string { return $this->frequency; }
    public function getFormula(): ?string { return $this->formula; }
    public function getSource(): ?string { return $this->source; }
    public function getTarget(): ?string { return $this->target; }
    /** @return array<string, int|float|string> */
    public function getThresholds(): array { return $this->thresholds; }
    public function isActive(): bool { return $this->active; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    /** @return Collection<int, IndicatorValue> */ public function getValues(): Collection { return $this->values; }

    /** @param array<string, int|float|string> $thresholds */
    public function configure(?string $formula, ?string $source, ?string $target, array $thresholds, bool $active): self
    {
        $this->formula = $formula;
        $this->source = $source;
        $this->target = $target;
        $this->thresholds = $thresholds;
        $this->active = $active;

        return $this;
    }
}
