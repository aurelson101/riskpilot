<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ContinuityProcessRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContinuityProcessRepository::class)] #[ORM\Table(name: 'continuity_processes')]
class ContinuityProcess
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Organization $organization;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private Scope $scope;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private User $owner;
    #[ORM\Column(length: 220)] private string $name;
    #[ORM\Column(length: 20)] private string $criticality;
    #[ORM\Column] private int $mtpdHours;
    #[ORM\Column] private int $rtoHours;
    #[ORM\Column] private int $rpoHours;
    /** @var list<string> */ #[ORM\Column(type: 'json')] private array $dependencies;
    #[ORM\Column(type: 'text')] private string $businessImpact;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $bcpProcedure = null;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $drpProcedure = null;
    /** @var list<array{date: string, scenario: string, participants: list<string>, result: string, gaps: list<string>, improvements: list<string>}> */ #[ORM\Column(type: 'json')] private array $exercises = [];
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $nextExerciseAt = null;
    #[ORM\Column] private \DateTimeImmutable $updatedAt;
    /** @param list<string> $dependencies */
    public function __construct(Organization $organization, Scope $scope, User $owner, string $name, string $criticality, int $mtpdHours, int $rtoHours, int $rpoHours, array $dependencies, string $businessImpact)
    {
        if ('' === trim($name) || !in_array($criticality, ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'], true) || $mtpdHours < 1 || $rtoHours < 0 || $rpoHours < 0 || $rtoHours > $mtpdHours || '' === trim($businessImpact)) {
            throw new \InvalidArgumentException('BIA invalide : le RTO doit notamment rester inférieur au MTPD.');
        } $this->organization = $organization;
        $this->scope = $scope;
        $this->owner = $owner;
        $this->name = trim($name);
        $this->criticality = $criticality;
        $this->mtpdHours = $mtpdHours;
        $this->rtoHours = $rtoHours;
        $this->rpoHours = $rpoHours;
        $this->dependencies = $dependencies;
        $this->businessImpact = trim($businessImpact);
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getScope(): Scope
    {
        return $this->scope;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCriticality(): string
    {
        return $this->criticality;
    }

    public function getMtpdHours(): int
    {
        return $this->mtpdHours;
    }

    public function getRtoHours(): int
    {
        return $this->rtoHours;
    }

    public function getRpoHours(): int
    {
        return $this->rpoHours;
    }

    /** @return list<string> */
    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    public function getBusinessImpact(): string
    {
        return $this->businessImpact;
    }

    public function getBcpProcedure(): ?string
    {
        return $this->bcpProcedure;
    }

    public function getDrpProcedure(): ?string
    {
        return $this->drpProcedure;
    }

    public function getNextExerciseAt(): ?\DateTimeImmutable
    {
        return $this->nextExerciseAt;
    }

    /** @return list<array{date: string, scenario: string, participants: list<string>, result: string, gaps: list<string>, improvements: list<string>}> */
    public function getExercises(): array
    {
        return $this->exercises;
    }

    /** @param list<string> $dependencies */
    public function update(Scope $scope, User $owner, string $name, string $criticality, int $mtpdHours, int $rtoHours, int $rpoHours, array $dependencies, string $businessImpact): void
    {
        if ('' === trim($name) || !in_array($criticality, ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'], true) || $mtpdHours < 1 || $rtoHours < 0 || $rpoHours < 0 || $rtoHours > $mtpdHours || '' === trim($businessImpact)) {
            throw new \InvalidArgumentException('BIA invalide : le RTO doit notamment rester inférieur au MTPD.');
        }
        $this->scope = $scope;
        $this->owner = $owner;
        $this->name = trim($name);
        $this->criticality = $criticality;
        $this->mtpdHours = $mtpdHours;
        $this->rtoHours = $rtoHours;
        $this->rpoHours = $rpoHours;
        $this->dependencies = $dependencies;
        $this->businessImpact = trim($businessImpact);
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setPlans(?string $bcp, ?string $drp, ?\DateTimeImmutable $nextExerciseAt): void
    {
        $this->bcpProcedure = null === $bcp || '' === trim($bcp) ? null : trim($bcp);
        $this->drpProcedure = null === $drp || '' === trim($drp) ? null : trim($drp);
        $this->nextExerciseAt = $nextExerciseAt;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @param list<string> $participants
     * @param list<string> $gaps
     * @param list<string> $improvements
     */
    public function recordExercise(\DateTimeImmutable $date, string $scenario, array $participants, string $result, array $gaps, array $improvements): void
    {
        if ('' === trim($scenario) || [] === $participants || '' === trim($result)) {
            throw new \InvalidArgumentException('Exercice de continuité invalide.');
        } $this->exercises[] = ['date' => $date->format('Y-m-d'), 'scenario' => trim($scenario), 'participants' => $participants, 'result' => trim($result), 'gaps' => $gaps, 'improvements' => $improvements];
        $this->updatedAt = new \DateTimeImmutable();
    }
}
