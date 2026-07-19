<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ThirdPartyRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ThirdPartyRepository::class)]
#[ORM\Table(name: 'third_parties')]
#[ORM\UniqueConstraint(name: 'uniq_third_party_org_name', columns: ['organization_id', 'name'])]
class ThirdParty
{
    public const CRITICALITIES = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];
    public const STATUSES = ['PROSPECT', 'ACTIVE', 'SUSPENDED', 'EXIT_PLANNED', 'TERMINATED'];
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Organization $organization;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private User $owner;
    #[ORM\Column(length: 200)] private string $name;
    #[ORM\Column(length: 180, nullable: true)] private ?string $contactEmail = null;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $services = null;
    /** @var list<string> */ #[ORM\Column(type: 'json')] private array $dataCategories = [];
    #[ORM\Column(length: 20)] private string $criticality;
    #[ORM\Column(length: 20)] private string $status = 'ACTIVE';
    #[ORM\Column(length: 200, nullable: true)] private ?string $contractReference = null;
    #[ORM\Column(length: 200, nullable: true)] private ?string $sla = null;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $dependencies = null;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $exitPlan = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $contractEndsAt = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $nextAssessmentAt = null;
    #[ORM\Column] private int $cyberScore = 0;
    /** @var list<string> */ #[ORM\Column(type: 'json')] private array $certifications = [];
    #[ORM\Column(type: 'text', nullable: true)] private ?string $riskSummary = null;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $compensatingMeasures = null;
    /** @var Collection<int, SupplierAssessment> */ #[ORM\OneToMany(mappedBy: 'thirdParty', targetEntity: SupplierAssessment::class, cascade: ['persist'], orphanRemoval: true)] private Collection $assessments;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    #[ORM\Column] private \DateTimeImmutable $updatedAt;
    public function __construct(Organization $organization, User $owner, string $name, string $criticality)
    {
        $this->organization = $organization;
        $this->owner = $owner;
        $this->assessments = new ArrayCollection();
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
        $this->update($name, null, null, [], $criticality, 'ACTIVE', null, null, null, null, null, null, $owner);
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

    public function getName(): string
    {
        return $this->name;
    }

    public function getContactEmail(): ?string
    {
        return $this->contactEmail;
    }

    public function getServices(): ?string
    {
        return $this->services;
    }

    /** @return list<string> */
    public function getDataCategories(): array
    {
        return $this->dataCategories;
    }

    public function getCriticality(): string
    {
        return $this->criticality;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getContractReference(): ?string
    {
        return $this->contractReference;
    }

    public function getSla(): ?string
    {
        return $this->sla;
    }

    public function getDependencies(): ?string
    {
        return $this->dependencies;
    }

    public function getExitPlan(): ?string
    {
        return $this->exitPlan;
    }

    public function getContractEndsAt(): ?\DateTimeImmutable
    {
        return $this->contractEndsAt;
    }

    public function getNextAssessmentAt(): ?\DateTimeImmutable
    {
        return $this->nextAssessmentAt;
    }

    public function getCyberScore(): int
    {
        return $this->cyberScore;
    }

    /** @return Collection<int, SupplierAssessment> */
    public function getAssessments(): Collection
    {
        return $this->assessments;
    }

    /** @param list<string> $dataCategories */
    public function update(string $name, ?string $contactEmail, ?string $services, array $dataCategories, string $criticality, string $status, ?string $contractReference, ?string $sla, ?string $dependencies, ?string $exitPlan, ?\DateTimeImmutable $contractEndsAt, ?\DateTimeImmutable $nextAssessmentAt, User $owner): void
    {
        if ('' === trim($name) || !in_array($criticality, self::CRITICALITIES, true) || !in_array($status, self::STATUSES, true) || (null !== $contactEmail && '' !== $contactEmail && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL))) {
            throw new \InvalidArgumentException('Tiers invalide.');
        } $this->name = trim($name);
        $this->contactEmail = $this->nullable($contactEmail);
        $this->services = $this->nullable($services);
        $this->dataCategories = $dataCategories;
        $this->criticality = $criticality;
        $this->status = $status;
        $this->contractReference = $this->nullable($contractReference);
        $this->sla = $this->nullable($sla);
        $this->dependencies = $this->nullable($dependencies);
        $this->exitPlan = $this->nullable($exitPlan);
        $this->contractEndsAt = $contractEndsAt;
        $this->nextAssessmentAt = $nextAssessmentAt;
        $this->owner = $owner;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function addAssessment(SupplierAssessment $assessment): void
    {
        if (!$this->assessments->contains($assessment)) {
            $this->assessments->add($assessment);
        }
    }

    /** @return list<string> */
    public function getCertifications(): array
    {
        return $this->certifications;
    }

    public function getRiskSummary(): ?string
    {
        return $this->riskSummary;
    }

    public function getCompensatingMeasures(): ?string
    {
        return $this->compensatingMeasures;
    }

    /** @param list<string> $certifications */
    public function assessRisk(array $certifications, ?string $riskSummary, ?string $compensatingMeasures): void
    {
        $this->certifications = $certifications;
        $this->riskSummary = $this->nullable($riskSummary);
        $this->compensatingMeasures = $this->nullable($compensatingMeasures);
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function setCyberScore(int $score): void
    {
        $this->cyberScore = max(0, min(100, $score));
        $this->updatedAt = new \DateTimeImmutable();
    }

    private function nullable(?string $value): ?string
    {
        return null === $value || '' === trim($value) ? null : trim($value);
    }
}
