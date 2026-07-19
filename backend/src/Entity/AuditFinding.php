<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'audit_findings')]
class AuditFinding
{
    public const TYPES = ['OBSERVATION', 'MINOR_NONCONFORMITY', 'MAJOR_NONCONFORMITY', 'OPPORTUNITY'];
    public const STATUSES = ['OPEN', 'ANALYSIS', 'ACTION_IN_PROGRESS', 'EFFECTIVENESS_REVIEW', 'CLOSED', 'REJECTED'];
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne(inversedBy: 'findings')] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private AuditEngagement $engagement;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private User $owner;
    #[ORM\Column(length: 30)] private string $type;
    #[ORM\Column(length: 220)] private string $title;
    #[ORM\Column(type: 'text')] private string $description;
    /** @var list<string> */ #[ORM\Column(type: 'json')] private array $evidence;
    #[ORM\Column] private \DateTimeImmutable $dueAt;
    #[ORM\Column(length: 30)] private string $status = 'OPEN';
    #[ORM\Column(type: 'text', nullable: true)] private ?string $rootCause = null;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $correction = null;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $correctiveAction = null;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $preventiveAction = null;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $effectivenessConclusion = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')] private ?User $effectivenessValidatedBy = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $effectivenessValidatedAt = null;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    /** @param list<string> $evidence */
    public function __construct(AuditEngagement $engagement, User $owner, string $type, string $title, string $description, array $evidence, \DateTimeImmutable $dueAt)
    {
        if (!in_array($type, self::TYPES, true) || '' === trim($title) || '' === trim($description)) {
            throw new \InvalidArgumentException('Constat d’audit invalide.');
        } $this->engagement = $engagement;
        $this->owner = $owner;
        $this->type = $type;
        $this->title = trim($title);
        $this->description = trim($description);
        $this->evidence = $evidence;
        $this->dueAt = $dueAt;
        $this->createdAt = new \DateTimeImmutable();
        $engagement->addFinding($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEngagement(): AuditEngagement
    {
        return $this->engagement;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    /** @return list<string> */
    public function getEvidence(): array
    {
        return $this->evidence;
    }

    public function getDueAt(): \DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getRootCause(): ?string
    {
        return $this->rootCause;
    }

    public function getCorrection(): ?string
    {
        return $this->correction;
    }

    public function getCorrectiveAction(): ?string
    {
        return $this->correctiveAction;
    }

    public function getPreventiveAction(): ?string
    {
        return $this->preventiveAction;
    }

    public function getEffectivenessConclusion(): ?string
    {
        return $this->effectivenessConclusion;
    }

    public function getEffectivenessValidatedBy(): ?User
    {
        return $this->effectivenessValidatedBy;
    }

    public function getEffectivenessValidatedAt(): ?\DateTimeImmutable
    {
        return $this->effectivenessValidatedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function planCapa(string $rootCause, string $correction, string $correctiveAction, ?string $preventiveAction): void
    {
        if ('' === trim($rootCause) || '' === trim($correction) || '' === trim($correctiveAction)) {
            throw new \InvalidArgumentException('Cause, correction et action corrective sont requises.');
        } $this->rootCause = trim($rootCause);
        $this->correction = trim($correction);
        $this->correctiveAction = trim($correctiveAction);
        $this->preventiveAction = null === $preventiveAction || '' === trim($preventiveAction) ? null : trim($preventiveAction);
        $this->status = 'ACTION_IN_PROGRESS';
    }

    public function requestEffectivenessReview(): void
    {
        if (null === $this->correctiveAction) {
            throw new \LogicException('Une CAPA doit être définie avant la revue d’efficacité.');
        } $this->status = 'EFFECTIVENESS_REVIEW';
    }

    public function validateEffectiveness(User $validator, bool $effective, string $conclusion): void
    {
        if ($validator === $this->owner || 'EFFECTIVENESS_REVIEW' !== $this->status || '' === trim($conclusion)) {
            throw new \LogicException('La validation d’efficacité doit être indépendante et motivée.');
        } $this->effectivenessValidatedBy = $validator;
        $this->effectivenessValidatedAt = new \DateTimeImmutable();
        $this->effectivenessConclusion = trim($conclusion);
        $this->status = $effective ? 'CLOSED' : 'ACTION_IN_PROGRESS';
    }
}
