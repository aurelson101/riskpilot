<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SecurityIncidentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SecurityIncidentRepository::class)] #[ORM\Table(name: 'security_incidents')]
class SecurityIncident
{
    public const SEVERITIES = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];
    public const STATUSES = ['DETECTED', 'QUALIFIED', 'CONTAINED', 'ERADICATED', 'RECOVERED', 'CLOSED'];
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Organization $organization;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private User $owner;
    #[ORM\Column(length: 220)] private string $title;
    #[ORM\Column(type: 'text')] private string $description;
    #[ORM\Column(length: 20)] private string $severity;
    #[ORM\Column(length: 20)] private string $status = 'DETECTED';
    #[ORM\Column] private \DateTimeImmutable $detectedAt;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $closedAt = null;
    /** @var array<string, mixed> */ #[ORM\Column(type: 'json')] private array $impacts = [];
    /** @var list<array{at: string, event: string, actor: string}> */ #[ORM\Column(type: 'json')] private array $timeline = [];
    /** @var list<string> */ #[ORM\Column(type: 'json')] private array $evidence = [];
    #[ORM\Column] private bool $regulatoryNotificationRequired = false;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $notifiedAt = null;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $lessonsLearned = null;
    /** @var Collection<int, Asset> */ #[ORM\ManyToMany(targetEntity: Asset::class)] #[ORM\JoinTable(name: 'incident_assets')] private Collection $assets;
    /** @var Collection<int, ThirdParty> */ #[ORM\ManyToMany(targetEntity: ThirdParty::class)] #[ORM\JoinTable(name: 'incident_third_parties')] private Collection $thirdParties;
    /** @var Collection<int, RiskScenario> */ #[ORM\ManyToMany(targetEntity: RiskScenario::class)] #[ORM\JoinTable(name: 'incident_risks')] private Collection $risks;
    /** @var Collection<int, ActionPlan> */ #[ORM\ManyToMany(targetEntity: ActionPlan::class)] #[ORM\JoinTable(name: 'incident_actions')] private Collection $actions;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    #[ORM\Column] private \DateTimeImmutable $updatedAt;
    public function __construct(Organization $organization, User $owner, string $title, string $description, string $severity, \DateTimeImmutable $detectedAt)
    {
        if ('' === trim($title) || '' === trim($description) || !in_array($severity, self::SEVERITIES, true)) {
            throw new \InvalidArgumentException('Incident invalide.');
        } $this->organization = $organization;
        $this->owner = $owner;
        $this->title = trim($title);
        $this->description = trim($description);
        $this->severity = $severity;
        $this->detectedAt = $detectedAt;
        $this->assets = new ArrayCollection();
        $this->thirdParties = new ArrayCollection();
        $this->risks = new ArrayCollection();
        $this->actions = new ArrayCollection();
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getSeverity(): string
    {
        return $this->severity;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getDetectedAt(): \DateTimeImmutable
    {
        return $this->detectedAt;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    /** @return array<string, mixed> */
    public function getImpacts(): array
    {
        return $this->impacts;
    }

    /** @return list<array{at: string, event: string, actor: string}> */
    public function getTimeline(): array
    {
        return $this->timeline;
    }

    /** @return list<string> */
    public function getEvidence(): array
    {
        return $this->evidence;
    }

    public function isRegulatoryNotificationRequired(): bool
    {
        return $this->regulatoryNotificationRequired;
    }

    public function getNotifiedAt(): ?\DateTimeImmutable
    {
        return $this->notifiedAt;
    }

    public function getLessonsLearned(): ?string
    {
        return $this->lessonsLearned;
    }

    /** @return Collection<int, Asset> */
    public function getAssets(): Collection
    {
        return $this->assets;
    }

    /** @return Collection<int, ThirdParty> */
    public function getThirdParties(): Collection
    {
        return $this->thirdParties;
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
     * @param array<string, mixed> $impacts
     * @param list<string>         $evidence
     * @param list<Asset>          $assets
     * @param list<ThirdParty>     $thirdParties
     * @param list<RiskScenario>   $risks
     * @param list<ActionPlan>     $actions
     */
    public function update(string $status, array $impacts, array $evidence, bool $notificationRequired, ?\DateTimeImmutable $notifiedAt, ?string $lessons, array $assets, array $thirdParties, array $risks, array $actions): void
    {
        if (!in_array($status, self::STATUSES, true) || ($notificationRequired && 'CLOSED' === $status && null === $notifiedAt)) {
            throw new \InvalidArgumentException('État ou notification de l’incident invalide.');
        } $this->status = $status;
        $this->impacts = $impacts;
        $this->evidence = $evidence;
        $this->regulatoryNotificationRequired = $notificationRequired;
        $this->notifiedAt = $notifiedAt;
        $this->lessonsLearned = null === $lessons || '' === trim($lessons) ? null : trim($lessons);
        $this->replace($this->assets, $assets);
        $this->replace($this->thirdParties, $thirdParties);
        $this->replace($this->risks, $risks);
        $this->replace($this->actions, $actions);
        $this->closedAt = 'CLOSED' === $status ? new \DateTimeImmutable() : null;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function addTimelineEvent(string $event, User $actor): void
    {
        if ('' === trim($event)) {
            throw new \InvalidArgumentException('Événement vide.');
        } $this->timeline[] = ['at' => (new \DateTimeImmutable())->format(DATE_ATOM), 'event' => trim($event), 'actor' => trim($actor->getFirstName().' '.$actor->getLastName())];
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @template T of object
     *
     * @param Collection<int, T> $target
     * @param list<T>            $values
     */
    private function replace(Collection $target, array $values): void
    {
        $target->clear();
        foreach ($values as $value) {
            $target->add($value);
        }
    }
}
