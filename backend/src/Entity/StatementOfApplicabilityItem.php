<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'statement_of_applicability_items')]
#[ORM\UniqueConstraint(name: 'uniq_soa_requirement', columns: ['statement_id', 'requirement_id'])]
class StatementOfApplicabilityItem
{
    public const IMPLEMENTATION_STATUSES = ['NOT_IMPLEMENTED', 'PLANNED', 'PARTIAL', 'IMPLEMENTED'];
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne(inversedBy: 'items')] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private StatementOfApplicability $statement;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private Requirement $requirement;
    #[ORM\Column] private bool $applicable = true;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $justification = null;
    #[ORM\Column(length: 30)] private string $implementationStatus = 'NOT_IMPLEMENTED';
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')] private ?User $owner = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $nextReviewAt = null;
    /** @var list<string> */ #[ORM\Column(type: 'json')] private array $evidence = [];
    /** @var Collection<int, SecurityControl> */ #[ORM\ManyToMany(targetEntity: SecurityControl::class)] #[ORM\JoinTable(name: 'soa_item_controls')] private Collection $controls;
    /** @var Collection<int, RiskScenario> */ #[ORM\ManyToMany(targetEntity: RiskScenario::class)] #[ORM\JoinTable(name: 'soa_item_risks')] private Collection $risks;
    /** @var Collection<int, ActionPlan> */ #[ORM\ManyToMany(targetEntity: ActionPlan::class)] #[ORM\JoinTable(name: 'soa_item_actions')] private Collection $actions;
    public function __construct(StatementOfApplicability $statement, Requirement $requirement)
    {
        $this->statement = $statement;
        $this->requirement = $requirement;
        $this->controls = new ArrayCollection();
        $this->risks = new ArrayCollection();
        $this->actions = new ArrayCollection();
        $statement->addItem($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStatement(): StatementOfApplicability
    {
        return $this->statement;
    }

    public function getRequirement(): Requirement
    {
        return $this->requirement;
    }

    public function isApplicable(): bool
    {
        return $this->applicable;
    }

    public function getJustification(): ?string
    {
        return $this->justification;
    }

    public function getImplementationStatus(): string
    {
        return $this->implementationStatus;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function getNextReviewAt(): ?\DateTimeImmutable
    {
        return $this->nextReviewAt;
    }

    /** @return list<string> */
    public function getEvidence(): array
    {
        return $this->evidence;
    }

    /** @return Collection<int, SecurityControl> */
    public function getControls(): Collection
    {
        return $this->controls;
    }

    /** @return Collection<int, RiskScenario> */
    public function getRisks(): Collection
    {
        return $this->risks;
    }

    /** @return Collection<int, ActionPlan> */
    public function getActions(): Collection
    {
        return $this->actions;
    }

    /**
     * @param list<string>          $evidence
     * @param list<SecurityControl> $controls
     * @param list<RiskScenario>    $risks
     * @param list<ActionPlan>      $actions
     */
    public function update(bool $applicable, ?string $justification, string $status, ?User $owner, ?\DateTimeImmutable $nextReviewAt, array $evidence, array $controls, array $risks, array $actions): void
    {
        if ('APPROVED' === $this->statement->getStatus()) {
            throw new \LogicException('Une SoA approuvée est immuable.');
        }
        if (!in_array($status, self::IMPLEMENTATION_STATUSES, true)) {
            throw new \InvalidArgumentException('Statut de mise en œuvre invalide.');
        }
        if (!$applicable && (null === $justification || '' === trim($justification))) {
            throw new \InvalidArgumentException('La non-applicabilité doit être justifiée.');
        }
        $this->applicable = $applicable;
        $this->justification = null === $justification || '' === trim($justification) ? null : trim($justification);
        $this->implementationStatus = $status;
        $this->owner = $owner;
        $this->nextReviewAt = $nextReviewAt;
        $this->evidence = $evidence;
        $this->replace($this->controls, $controls);
        $this->replace($this->risks, $risks);
        $this->replace($this->actions, $actions);
    }

    /**
     * @template T of object
     *
     * @param Collection<int, T> $collection
     * @param list<T>            $items
     */
    private function replace(Collection $collection, array $items): void
    {
        $collection->clear();
        foreach ($items as $item) {
            $collection->add($item);
        }
    }
}
