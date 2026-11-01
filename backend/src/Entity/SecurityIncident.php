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
    public const CATEGORIES = ['SECURITY', 'AI', 'PRIVACY', 'OTHER'];
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
    #[ORM\Column(length: 20, options: ['default' => 'SECURITY'])] private string $category = 'SECURITY';
    #[ORM\Column(options: ['default' => false])] private bool $significant = false;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $earlyWarningAt = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $finalReportAt = null;
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

    public function getCategory(): string
    {
        return $this->category;
    }

    public function isSignificant(): bool
    {
        return $this->significant;
    }

    public function getEarlyWarningAt(): ?\DateTimeImmutable
    {
        return $this->earlyWarningAt;
    }

    public function getFinalReportAt(): ?\DateTimeImmutable
    {
        return $this->finalReportAt;
    }

    /**
     * Renseigne la qualification NIS2 : incident significatif et dates de dépôt des déclarations.
     * Un incident significatif impose la notification réglementaire et ne peut être clos sans les trois déclarations.
     */
    public function configureNis2(string $category, bool $significant, ?\DateTimeImmutable $earlyWarningAt, ?\DateTimeImmutable $finalReportAt, User $actor): void
    {
        if (!in_array($category, self::CATEGORIES, true)) {
            throw new \InvalidArgumentException('Catégorie d’incident invalide.');
        }
        foreach ([$earlyWarningAt, $this->notifiedAt, $finalReportAt] as $date) {
            if (null !== $date && $date < $this->detectedAt) {
                throw new \InvalidArgumentException('Une déclaration ne peut pas précéder la détection de l’incident.');
            }
        }
        if (null !== $finalReportAt && null === $this->notifiedAt) {
            throw new \InvalidArgumentException('Le rapport final suppose que la notification a été déposée.');
        }
        if ($significant && 'CLOSED' === $this->status && (null === $earlyWarningAt || null === $this->notifiedAt || null === $finalReportAt)) {
            throw new \InvalidArgumentException('Un incident significatif ne peut être clos sans alerte précoce, notification et rapport final.');
        }
        if ($significant && !$this->significant) {
            $this->addTimelineEvent('Incident qualifié de significatif (NIS2)', $actor);
        }
        if (null !== $earlyWarningAt && null === $this->earlyWarningAt) {
            $this->addTimelineEvent('Alerte précoce déposée', $actor);
        }
        if (null !== $finalReportAt && null === $this->finalReportAt) {
            $this->addTimelineEvent('Rapport final déposé', $actor);
        }
        $this->category = $category;
        $this->significant = $significant;
        $this->earlyWarningAt = $earlyWarningAt;
        $this->finalReportAt = $finalReportAt;
        if ($significant) {
            $this->regulatoryNotificationRequired = true;
        }
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

    public function revise(string $title, string $description, string $severity, User $owner): void
    {
        if ('' === trim($title) || '' === trim($description) || !in_array($severity, self::SEVERITIES, true)) {
            throw new \InvalidArgumentException('Incident invalide.');
        }
        $this->title = trim($title);
        $this->description = trim($description);
        $this->severity = $severity;
        $this->owner = $owner;
        $this->updatedAt = new \DateTimeImmutable();
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
