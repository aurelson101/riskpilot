<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\IctArrangementRepository;
use Doctrine\ORM\Mapping as ORM;

/** Accord contractuel de services TIC avec un prestataire tiers : une ligne du registre d'informations DORA (art. 28 §3). */
#[ORM\Entity(repositoryClass: IctArrangementRepository::class)]
#[ORM\Table(name: 'ict_arrangements')]
class IctArrangement
{
    public const SERVICE_TYPES = ['CLOUD_INFRASTRUCTURE', 'SAAS', 'DATA_CENTER', 'NETWORK', 'SECURITY_SERVICES', 'DATA_ANALYTICS', 'PAYMENT_PROCESSING', 'OTHER'];
    public const SUBSTITUTABILITY = ['NOT_ASSESSED', 'EASY', 'DIFFICULT', 'IMPOSSIBLE'];
    public const STATUSES = ['PLANNED', 'ACTIVE', 'TERMINATED'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Organization $organization;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private ThirdParty $thirdParty;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private User $owner;
    #[ORM\Column(length: 30)] private string $serviceType;
    #[ORM\Column(type: 'text')] private string $description;
    #[ORM\Column(options: ['default' => false])] private bool $supportsCriticalFunction = false;
    #[ORM\Column(length: 200, nullable: true)] private ?string $functionSupported = null;
    #[ORM\Column(length: 20, nullable: true)] private ?string $providerLei = null;
    #[ORM\Column(length: 200, nullable: true)] private ?string $contractReference = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $startsAt = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $endsAt = null;
    #[ORM\Column(length: 120, nullable: true)] private ?string $dataLocation = null;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $subcontractors = null;
    #[ORM\Column(length: 20)] private string $substitutability = 'NOT_ASSESSED';
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $exitPlanTestedAt = null;
    #[ORM\Column(length: 100, nullable: true)] private ?string $governingLaw = null;
    #[ORM\Column(type: 'smallint', nullable: true)] private ?int $terminationNoticeDays = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $lastAuditedAt = null;
    #[ORM\Column(length: 20)] private string $status = 'ACTIVE';
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    #[ORM\Column] private \DateTimeImmutable $updatedAt;
    public function __construct(Organization $organization, ThirdParty $thirdParty, User $owner, string $serviceType, string $description)
    {
        if ($thirdParty->getOrganization() !== $organization) {
            throw new \InvalidArgumentException('Le prestataire appartient à une autre organisation.');
        }
        $this->organization = $organization;
        $this->thirdParty = $thirdParty;
        $this->createdAt = new \DateTimeImmutable();
        $this->update($owner, $serviceType, $description, false, null, null, null, null, null, null, null, 'NOT_ASSESSED', null, 'ACTIVE');
    }

    public function update(User $owner, string $serviceType, string $description, bool $supportsCriticalFunction, ?string $functionSupported, ?string $providerLei, ?string $contractReference, ?\DateTimeImmutable $startsAt, ?\DateTimeImmutable $endsAt, ?string $dataLocation, ?string $subcontractors, string $substitutability, ?\DateTimeImmutable $exitPlanTestedAt, string $status): void
    {
        if (!in_array($serviceType, self::SERVICE_TYPES, true) || '' === trim($description)
            || !in_array($substitutability, self::SUBSTITUTABILITY, true) || !in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Accord de services TIC invalide.');
        }
        if (null !== $startsAt && null !== $endsAt && $endsAt <= $startsAt) {
            throw new \InvalidArgumentException('La fin du contrat doit suivre son début.');
        }
        $lei = null === $providerLei ? null : strtoupper(trim($providerLei));
        if (null !== $lei && '' !== $lei && 1 !== preg_match('/^[A-Z0-9]{18}[0-9]{2}$/', $lei)) {
            throw new \InvalidArgumentException('Le LEI doit comporter 20 caractères (18 alphanumériques et 2 chiffres de contrôle).');
        }
        $this->owner = $owner;
        $this->serviceType = $serviceType;
        $this->description = trim($description);
        $this->supportsCriticalFunction = $supportsCriticalFunction;
        $this->functionSupported = $this->clean($functionSupported, 200);
        $this->providerLei = '' === $lei ? null : $lei;
        $this->contractReference = $this->clean($contractReference, 200);
        $this->startsAt = $startsAt;
        $this->endsAt = $endsAt;
        $this->dataLocation = $this->clean($dataLocation, 120);
        $this->subcontractors = null === $subcontractors || '' === trim($subcontractors) ? null : trim($subcontractors);
        $this->substitutability = $substitutability;
        $this->exitPlanTestedAt = $exitPlanTestedAt;
        $this->status = $status;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** Conditions contractuelles complémentaires du registre (droit applicable, préavis de résiliation, dernier audit du prestataire). */
    public function setContractTerms(?string $governingLaw, ?int $terminationNoticeDays, ?\DateTimeImmutable $lastAuditedAt): void
    {
        if (null !== $terminationNoticeDays && ($terminationNoticeDays < 0 || $terminationNoticeDays > 3650)) {
            throw new \InvalidArgumentException('Le préavis de résiliation doit être compris entre 0 et 3650 jours.');
        }
        $this->governingLaw = $this->clean($governingLaw, 100);
        $this->terminationNoticeDays = $terminationNoticeDays;
        $this->lastAuditedAt = $lastAuditedAt;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getGoverningLaw(): ?string
    {
        return $this->governingLaw;
    }

    public function getTerminationNoticeDays(): ?int
    {
        return $this->terminationNoticeDays;
    }

    public function getLastAuditedAt(): ?\DateTimeImmutable
    {
        return $this->lastAuditedAt;
    }

    private function clean(?string $value, int $limit): ?string
    {
        $value = null === $value ? '' : trim($value);
        if (mb_strlen($value) > $limit) {
            throw new \InvalidArgumentException('Un champ texte dépasse la longueur autorisée.');
        }

        return '' === $value ? null : $value;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getThirdParty(): ThirdParty
    {
        return $this->thirdParty;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getServiceType(): string
    {
        return $this->serviceType;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function supportsCriticalFunction(): bool
    {
        return $this->supportsCriticalFunction;
    }

    public function getFunctionSupported(): ?string
    {
        return $this->functionSupported;
    }

    public function getProviderLei(): ?string
    {
        return $this->providerLei;
    }

    public function getContractReference(): ?string
    {
        return $this->contractReference;
    }

    public function getStartsAt(): ?\DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getEndsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function getDataLocation(): ?string
    {
        return $this->dataLocation;
    }

    public function getSubcontractors(): ?string
    {
        return $this->subcontractors;
    }

    public function getSubstitutability(): string
    {
        return $this->substitutability;
    }

    public function getExitPlanTestedAt(): ?\DateTimeImmutable
    {
        return $this->exitPlanTestedAt;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
