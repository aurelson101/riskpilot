<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'audit_engagements')]
class AuditEngagement
{
    public const STATUSES = ['PLANNED', 'IN_PROGRESS', 'REPORT_REVIEW', 'COMPLETED', 'CANCELLED'];
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne(inversedBy: 'engagements')] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private AuditProgram $program;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private Scope $scope;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private User $leadAuditor;
    /** @var Collection<int, User> */ #[ORM\ManyToMany(targetEntity: User::class)] #[ORM\JoinTable(name: 'audit_engagement_team')] private Collection $team;
    #[ORM\Column(length: 200)] private string $title;
    #[ORM\Column(type: 'text')] private string $independenceStatement;
    #[ORM\Column] private \DateTimeImmutable $startsAt;
    #[ORM\Column] private \DateTimeImmutable $endsAt;
    #[ORM\Column(length: 20)] private string $status = 'PLANNED';
    #[ORM\Column(length: 255, nullable: true)] private ?string $finalReportReference = null;
    /** @var Collection<int, AuditFinding> */ #[ORM\OneToMany(mappedBy: 'engagement', targetEntity: AuditFinding::class, cascade: ['persist'], orphanRemoval: true)] private Collection $findings;

    public function __construct(AuditProgram $program, Scope $scope, User $leadAuditor, string $title, string $independenceStatement, \DateTimeImmutable $startsAt, \DateTimeImmutable $endsAt)
    {
        if ('' === trim($title) || '' === trim($independenceStatement) || $endsAt < $startsAt) {
            throw new \InvalidArgumentException('Mission d’audit invalide.');
        } $this->program = $program;
        $this->scope = $scope;
        $this->leadAuditor = $leadAuditor;
        $this->title = trim($title);
        $this->independenceStatement = trim($independenceStatement);
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->team = new ArrayCollection([$leadAuditor]);
        $this->findings = new ArrayCollection();
        $program->addEngagement($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProgram(): AuditProgram
    {
        return $this->program;
    }

    public function getScope(): Scope
    {
        return $this->scope;
    }

    public function getLeadAuditor(): User
    {
        return $this->leadAuditor;
    }

    /** @return Collection<int, User> */
    public function getTeam(): Collection
    {
        return $this->team;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getIndependenceStatement(): string
    {
        return $this->independenceStatement;
    }

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getEndsAt(): \DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getFinalReportReference(): ?string
    {
        return $this->finalReportReference;
    }

    /** @return Collection<int, AuditFinding> */
    public function getFindings(): Collection
    {
        return $this->findings;
    }

    /** @param list<User> $team */
    public function update(string $status, array $team, ?string $reportReference): void
    {
        if (!in_array($status, self::STATUSES, true) || !in_array($this->leadAuditor, $team, true)) {
            throw new \InvalidArgumentException('Statut ou équipe d’audit invalide.');
        } $this->status = $status;
        $this->team->clear();
        foreach ($team as $member) {
            $this->team->add($member);
        } $this->finalReportReference = null === $reportReference || '' === trim($reportReference) ? null : trim($reportReference);
    }

    public function addFinding(AuditFinding $finding): void
    {
        if (!$this->findings->contains($finding)) {
            $this->findings->add($finding);
        }
    }
}
