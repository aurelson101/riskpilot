<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RiskReviewCampaignRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RiskReviewCampaignRepository::class)]
#[ORM\Table(name: 'risk_review_campaigns')]
class RiskReviewCampaign
{
    public const STATUSES = ['DRAFT', 'ACTIVE', 'COMPLETED', 'CANCELLED'];
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Organization $organization;
    #[ORM\Column(length: 200)] private string $title;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $description = null;
    #[ORM\Column(length: 20)] private string $status = 'DRAFT';
    #[ORM\Column] private \DateTimeImmutable $startsAt;
    #[ORM\Column] private \DateTimeImmutable $dueAt;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private User $coordinator;
    /** @var Collection<int, RiskReview> */
    #[ORM\OneToMany(mappedBy: 'campaign', targetEntity: RiskReview::class, cascade: ['persist'], orphanRemoval: true)] private Collection $reviews;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    public function __construct(Organization $organization, string $title, \DateTimeImmutable $startsAt, \DateTimeImmutable $dueAt, User $coordinator)
    {
        $this->organization = $organization;
        $this->title = trim($title);
        $this->startsAt = $startsAt;
        $this->dueAt = $dueAt;
        $this->coordinator = $coordinator;
        $this->reviews = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getDueAt(): \DateTimeImmutable
    {
        return $this->dueAt;
    }

    public function getCoordinator(): User
    {
        return $this->coordinator;
    }

    /** @return Collection<int, RiskReview> */
    public function getReviews(): Collection
    {
        return $this->reviews;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function configure(?string $description, string $status): void
    {
        $this->description = null === $description || '' === trim($description) ? null : trim($description);
        $this->status = $status;
    }

    public function addReview(RiskReview $review): void
    {
        if (!$this->reviews->contains($review)) {
            $this->reviews->add($review);
        }
    }

    public function completeIfReviewed(): void
    {
        if (!$this->reviews->isEmpty() && $this->reviews->forAll(static fn (int $key, RiskReview $review): bool => 'COMPLETED' === $review->getStatus())) {
            $this->status = 'COMPLETED';
        }
    }
}
