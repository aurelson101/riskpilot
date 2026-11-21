<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DigitalProductRepository;
use Doctrine\ORM\Mapping as ORM;

/** Produit comportant des éléments numériques entrant dans le champ du Cyber Resilience Act (règlement 2024/2847). */
#[ORM\Entity(repositoryClass: DigitalProductRepository::class)]
#[ORM\Table(name: 'digital_products')]
#[ORM\UniqueConstraint(name: 'uniq_digital_product_org_name_version', columns: ['organization_id', 'name', 'version'])]
class DigitalProduct
{
    public const CATEGORIES = ['DEFAULT', 'IMPORTANT_CLASS_I', 'IMPORTANT_CLASS_II', 'CRITICAL'];
    public const ROLES = ['MANUFACTURER', 'IMPORTER', 'DISTRIBUTOR', 'OPEN_SOURCE_STEWARD'];
    public const STATUSES = ['IN_DEVELOPMENT', 'ON_MARKET', 'SUPPORT_ENDED', 'WITHDRAWN'];
    public const CONFORMITY_ROUTES = ['NOT_ASSESSED', 'INTERNAL_CONTROL', 'THIRD_PARTY', 'EU_CERTIFICATION'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Organization $organization;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private User $owner;
    #[ORM\Column(length: 180)] private string $name;
    #[ORM\Column(length: 60)] private string $version;
    #[ORM\Column(length: 20)] private string $category = 'DEFAULT';
    #[ORM\Column(length: 30)] private string $role = 'MANUFACTURER';
    #[ORM\Column(length: 20)] private string $status = 'IN_DEVELOPMENT';
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $placedOnMarketAt = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $supportEndsAt = null;
    #[ORM\Column(length: 255, nullable: true)] private ?string $sbomReference = null;
    #[ORM\Column(length: 255, nullable: true)] private ?string $vulnerabilityContact = null;
    #[ORM\Column(length: 20)] private string $conformityRoute = 'NOT_ASSESSED';
    #[ORM\Column(length: 120, nullable: true)] private ?string $declarationReference = null;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    #[ORM\Column] private \DateTimeImmutable $updatedAt;
    public function __construct(Organization $organization, User $owner, string $name, string $version)
    {
        $this->organization = $organization;
        $this->createdAt = new \DateTimeImmutable();
        $this->update($owner, $name, $version, 'DEFAULT', 'MANUFACTURER', 'IN_DEVELOPMENT', null, null, null, null, 'NOT_ASSESSED', null);
    }

    public function update(User $owner, string $name, string $version, string $category, string $role, string $status, ?\DateTimeImmutable $placedOnMarketAt, ?\DateTimeImmutable $supportEndsAt, ?string $sbomReference, ?string $vulnerabilityContact, string $conformityRoute, ?string $declarationReference): void
    {
        if ('' === trim($name) || mb_strlen(trim($name)) > 180 || '' === trim($version) || mb_strlen(trim($version)) > 60
            || !in_array($category, self::CATEGORIES, true) || !in_array($role, self::ROLES, true)
            || !in_array($status, self::STATUSES, true) || !in_array($conformityRoute, self::CONFORMITY_ROUTES, true)) {
            throw new \InvalidArgumentException('Produit invalide.');
        }
        if (null !== $placedOnMarketAt && null !== $supportEndsAt && $supportEndsAt <= $placedOnMarketAt) {
            throw new \InvalidArgumentException('La fin de support doit suivre la mise sur le marché.');
        }
        if ('ON_MARKET' === $status && null === $placedOnMarketAt) {
            throw new \InvalidArgumentException('Un produit sur le marché exige sa date de mise sur le marché.');
        }
        $this->owner = $owner;
        $this->name = trim($name);
        $this->version = trim($version);
        $this->category = $category;
        $this->role = $role;
        $this->status = $status;
        $this->placedOnMarketAt = $placedOnMarketAt;
        $this->supportEndsAt = $supportEndsAt;
        $this->sbomReference = $this->clean($sbomReference, 255);
        $this->vulnerabilityContact = $this->clean($vulnerabilityContact, 255);
        $this->conformityRoute = $conformityRoute;
        $this->declarationReference = $this->clean($declarationReference, 120);
        $this->updatedAt = new \DateTimeImmutable();
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

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getPlacedOnMarketAt(): ?\DateTimeImmutable
    {
        return $this->placedOnMarketAt;
    }

    public function getSupportEndsAt(): ?\DateTimeImmutable
    {
        return $this->supportEndsAt;
    }

    public function getSbomReference(): ?string
    {
        return $this->sbomReference;
    }

    public function getVulnerabilityContact(): ?string
    {
        return $this->vulnerabilityContact;
    }

    public function getConformityRoute(): string
    {
        return $this->conformityRoute;
    }

    public function getDeclarationReference(): ?string
    {
        return $this->declarationReference;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
