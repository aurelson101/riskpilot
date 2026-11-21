<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Regulatory\RegulatoryDeadlines;
use App\Repository\RegulatoryIncidentRepository;
use Doctrine\ORM\Mapping as ORM;

/** Incident ou vulnérabilité soumis à notification réglementaire (DORA art. 19, CRA art. 14, AI Act art. 73). */
#[ORM\Entity(repositoryClass: RegulatoryIncidentRepository::class)]
#[ORM\Table(name: 'regulatory_incidents')]
#[ORM\Index(name: 'idx_regulatory_incident_org_regime', columns: ['organization_id', 'regime'])]
class RegulatoryIncident
{
    public const STATUSES = ['OPEN', 'CLOSED'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Organization $organization;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private User $owner;
    #[ORM\Column(length: 30)] private string $regime;
    #[ORM\Column(length: 220)] private string $title;
    #[ORM\Column(type: 'text')] private string $description;
    #[ORM\Column] private \DateTimeImmutable $awareAt;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $classifiedAt = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $correctiveAvailableAt = null;
    #[ORM\Column(options: ['default' => false])] private bool $widespread = false;
    #[ORM\Column(options: ['default' => false])] private bool $criticalInfrastructure = false;
    #[ORM\Column(options: ['default' => false])] private bool $death = false;
    #[ORM\Column(options: ['default' => true])] private bool $applicable = true;
    #[ORM\Column(length: 10)] private string $status = 'OPEN';
    /** @var array<string, string> dates de dépôt (ISO 8601) par clé d'étape */
    #[ORM\Column(type: 'json')] private array $completed = [];
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')] private ?AiSystem $aiSystem = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')] private ?DigitalProduct $product = null;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    #[ORM\Column] private \DateTimeImmutable $updatedAt;
    public function __construct(Organization $organization, User $owner, string $regime, string $title, string $description, \DateTimeImmutable $awareAt)
    {
        if (!in_array($regime, RegulatoryDeadlines::REGIMES, true)) {
            throw new \InvalidArgumentException('Régime de notification inconnu.');
        }
        $this->organization = $organization;
        $this->regime = $regime;
        $this->createdAt = new \DateTimeImmutable();
        $this->describe($owner, $title, $description, $awareAt);
    }

    public function describe(User $owner, string $title, string $description, \DateTimeImmutable $awareAt): void
    {
        if ('' === trim($title) || mb_strlen(trim($title)) > 220 || '' === trim($description)) {
            throw new \InvalidArgumentException('Le titre et la description sont obligatoires.');
        }
        if ($awareAt > new \DateTimeImmutable('+5 minutes')) {
            throw new \InvalidArgumentException('La date de prise de connaissance ne peut pas être dans le futur.');
        }
        $this->owner = $owner;
        $this->title = trim($title);
        $this->description = trim($description);
        $this->awareAt = $awareAt;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** Précise les faits qui déterminent les délais ; chaque fait n'est admis que pour le régime concerné. */
    public function qualify(bool $applicable, ?\DateTimeImmutable $classifiedAt, ?\DateTimeImmutable $correctiveAvailableAt, bool $widespread, bool $criticalInfrastructure, bool $death): void
    {
        if (null !== $classifiedAt && RegulatoryDeadlines::DORA !== $this->regime) {
            throw new \InvalidArgumentException('La date de qualification ne concerne que DORA.');
        }
        if (null !== $correctiveAvailableAt && RegulatoryDeadlines::CRA_VULNERABILITY !== $this->regime) {
            throw new \InvalidArgumentException('La date de correctif ne concerne que les vulnérabilités CRA.');
        }
        if (($widespread || $criticalInfrastructure || $death) && RegulatoryDeadlines::AI_ACT !== $this->regime) {
            throw new \InvalidArgumentException('Ces qualifications ne concernent que les incidents graves de l’AI Act.');
        }
        foreach (['qualification' => $classifiedAt, 'correctif' => $correctiveAvailableAt] as $label => $date) {
            if (null !== $date && $date < $this->awareAt) {
                throw new \InvalidArgumentException(sprintf('La date de %s ne peut pas précéder la prise de connaissance.', $label));
            }
        }
        $this->applicable = $applicable;
        $this->classifiedAt = $classifiedAt;
        $this->correctiveAvailableAt = $correctiveAvailableAt;
        $this->widespread = $widespread;
        $this->criticalInfrastructure = $criticalInfrastructure;
        $this->death = $death;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Enregistre les dépôts effectués et le statut ; une clôture exige que toutes les étapes applicables soient déposées.
     *
     * @param array<string, \DateTimeImmutable> $completed
     */
    public function record(array $completed, string $status): void
    {
        $keys = (new RegulatoryDeadlines())->stepKeys($this->regime);
        $now = new \DateTimeImmutable('+5 minutes');
        $stored = [];
        foreach ($completed as $key => $date) {
            if (!in_array($key, $keys, true)) {
                throw new \InvalidArgumentException(sprintf('Étape inconnue : %s.', $key));
            }
            if ($date < $this->awareAt || $date > $now) {
                throw new \InvalidArgumentException(sprintf('La date de dépôt de l’étape %s est incohérente.', $key));
            }
            $stored[$key] = $date->format(DATE_ATOM);
        }
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Statut invalide.');
        }
        if ('CLOSED' === $status && $this->applicable && [] !== array_diff($keys, array_keys($stored))) {
            throw new \InvalidArgumentException('Toutes les notifications applicables doivent être déposées avant la clôture.');
        }
        $this->completed = $stored;
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function link(?AiSystem $aiSystem, ?DigitalProduct $product): void
    {
        if (null !== $aiSystem && RegulatoryDeadlines::AI_ACT !== $this->regime) {
            throw new \InvalidArgumentException('Un système d’IA ne peut être lié qu’à un incident AI Act.');
        }
        if (null !== $product && !in_array($this->regime, [RegulatoryDeadlines::CRA_VULNERABILITY, RegulatoryDeadlines::CRA_INCIDENT], true)) {
            throw new \InvalidArgumentException('Un produit ne peut être lié qu’à un signalement CRA.');
        }
        $this->aiSystem = $aiSystem;
        $this->product = $product;
    }

    /** @return array{classifiedAt: ?\DateTimeImmutable, correctiveAvailableAt: ?\DateTimeImmutable, widespread: bool, criticalInfrastructure: bool, death: bool} */
    public function deadlineFacts(): array
    {
        return ['classifiedAt' => $this->classifiedAt, 'correctiveAvailableAt' => $this->correctiveAvailableAt, 'widespread' => $this->widespread, 'criticalInfrastructure' => $this->criticalInfrastructure, 'death' => $this->death];
    }

    /** @return array<string, \DateTimeImmutable> */
    public function completedDates(): array
    {
        return array_map(static fn (string $date): \DateTimeImmutable => new \DateTimeImmutable($date), $this->completed);
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

    public function getRegime(): string
    {
        return $this->regime;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getAwareAt(): \DateTimeImmutable
    {
        return $this->awareAt;
    }

    public function isApplicable(): bool
    {
        return $this->applicable;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getAiSystem(): ?AiSystem
    {
        return $this->aiSystem;
    }

    public function getProduct(): ?DigitalProduct
    {
        return $this->product;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
