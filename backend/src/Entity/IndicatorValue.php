<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IndicatorValueRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: IndicatorValueRepository::class)]
#[ORM\Table(name: 'indicator_values')]
#[ORM\UniqueConstraint(name: 'uniq_indicator_idempotency', columns: ['indicator_id', 'idempotency_key'])]
class IndicatorValue
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne(inversedBy: 'values')] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Indicator $indicator;
    #[ORM\Column(type: 'decimal', precision: 20, scale: 6)] private string $value;
    #[ORM\Column] private \DateTimeImmutable $measuredAt;
    #[ORM\Column(length: 80, nullable: true)] private ?string $period = null;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $comment = null;
    #[ORM\Column(length: 2048, nullable: true)] private ?string $evidence = null;
    #[ORM\Column(length: 255, nullable: true)] private ?string $source = null;
    #[ORM\Column(length: 120)] private string $idempotencyKey;
    #[ORM\Column] private int $version = 1;
    #[ORM\Column] private \DateTimeImmutable $createdAt;

    public function __construct(Indicator $indicator, string $value, \DateTimeImmutable $measuredAt, string $idempotencyKey)
    {
        $this->indicator = $indicator;
        $this->value = $value;
        $this->measuredAt = $measuredAt;
        $this->idempotencyKey = $idempotencyKey;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getIndicator(): Indicator { return $this->indicator; }
    public function getValue(): string { return $this->value; }
    public function getMeasuredAt(): \DateTimeImmutable { return $this->measuredAt; }
    public function getPeriod(): ?string { return $this->period; }
    public function getComment(): ?string { return $this->comment; }
    public function getEvidence(): ?string { return $this->evidence; }
    public function getSource(): ?string { return $this->source; }
    public function getIdempotencyKey(): string { return $this->idempotencyKey; }
    public function getVersion(): int { return $this->version; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function describe(?string $period, ?string $comment, ?string $evidence, ?string $source): self
    {
        $this->period = $period; $this->comment = $comment; $this->evidence = $evidence; $this->source = $source;
        return $this;
    }
}
