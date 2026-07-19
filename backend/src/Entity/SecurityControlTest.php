<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SecurityControlTestRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SecurityControlTestRepository::class)]
#[ORM\Table(name: 'security_control_tests')]
class SecurityControlTest
{
    public const TYPES = ['DESIGN', 'OPERATING_EFFECTIVENESS'];
    public const RESULTS = ['EFFECTIVE', 'PARTIALLY_EFFECTIVE', 'INEFFECTIVE', 'NOT_TESTED'];
    public const FREQUENCIES = ['MONTHLY', 'QUARTERLY', 'SEMI_ANNUAL', 'ANNUAL', 'AD_HOC'];
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Organization $organization;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private SecurityControl $control;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private User $tester;
    #[ORM\Column(length: 30)] private string $type;
    #[ORM\Column(length: 30)] private string $frequency;
    #[ORM\Column(length: 30)] private string $result = 'NOT_TESTED';
    #[ORM\Column(type: 'text')] private string $procedure;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $sampleDescription = null;
    #[ORM\Column(nullable: true)] private ?int $sampleSize = null;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $conclusion = null;
    /** @var list<string> */ #[ORM\Column(type: 'json')] private array $evidence = [];
    #[ORM\Column] private \DateTimeImmutable $performedAt;
    #[ORM\Column] private \DateTimeImmutable $nextReviewAt;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    public function __construct(SecurityControl $control, User $tester, string $type, string $frequency, string $procedure, \DateTimeImmutable $performedAt, \DateTimeImmutable $nextReviewAt)
    {
        if (!in_array($type, self::TYPES, true) || !in_array($frequency, self::FREQUENCIES, true) || '' === trim($procedure) || $nextReviewAt < $performedAt) {
            throw new \InvalidArgumentException('Paramètres du test de contrôle invalides.');
        } $this->organization = $control->getOrganization();
        $this->control = $control;
        $this->tester = $tester;
        $this->type = $type;
        $this->frequency = $frequency;
        $this->procedure = trim($procedure);
        $this->performedAt = $performedAt;
        $this->nextReviewAt = $nextReviewAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getControl(): SecurityControl
    {
        return $this->control;
    }

    public function getTester(): User
    {
        return $this->tester;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getFrequency(): string
    {
        return $this->frequency;
    }

    public function getResult(): string
    {
        return $this->result;
    }

    public function getProcedure(): string
    {
        return $this->procedure;
    }

    public function getSampleDescription(): ?string
    {
        return $this->sampleDescription;
    }

    public function getSampleSize(): ?int
    {
        return $this->sampleSize;
    }

    public function getConclusion(): ?string
    {
        return $this->conclusion;
    }

    /** @return list<string> */
    public function getEvidence(): array
    {
        return $this->evidence;
    }

    public function getPerformedAt(): \DateTimeImmutable
    {
        return $this->performedAt;
    }

    public function getNextReviewAt(): \DateTimeImmutable
    {
        return $this->nextReviewAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @param list<string> $evidence */
    public function conclude(string $result, ?string $sampleDescription, ?int $sampleSize, ?string $conclusion, array $evidence): void
    {
        if (!in_array($result, self::RESULTS, true) || (null !== $sampleSize && $sampleSize < 0)) {
            throw new \InvalidArgumentException('Résultat ou échantillon invalide.');
        } $this->result = $result;
        $this->sampleDescription = null === $sampleDescription || '' === trim($sampleDescription) ? null : trim($sampleDescription);
        $this->sampleSize = $sampleSize;
        $this->conclusion = null === $conclusion || '' === trim($conclusion) ? null : trim($conclusion);
        $this->evidence = $evidence;
    }
}
