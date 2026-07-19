<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RiskReviewRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RiskReviewRepository::class)]
#[ORM\Table(name: 'risk_reviews')]
#[ORM\UniqueConstraint(name: 'uniq_campaign_risk', columns: ['campaign_id', 'risk_id'])]
class RiskReview
{
    public const STATUSES = ['PENDING', 'IN_PROGRESS', 'COMPLETED'];
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne(inversedBy: 'reviews')] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private RiskReviewCampaign $campaign;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private RiskScenario $risk;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private User $reviewer;
    #[ORM\Column(length: 20)] private string $status = 'PENDING';
    #[ORM\Column] private int $baselineScore;
    #[ORM\Column(nullable: true)] private ?int $reviewedScore = null;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $comment = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $completedAt = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $lastReminderAt = null;
    public function __construct(RiskReviewCampaign $campaign, RiskScenario $risk, User $reviewer)
    {
        $this->campaign = $campaign;
        $this->risk = $risk;
        $this->reviewer = $reviewer;
        $this->baselineScore = $risk->getCurrentRiskScore();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCampaign(): RiskReviewCampaign
    {
        return $this->campaign;
    }

    public function getRisk(): RiskScenario
    {
        return $this->risk;
    }

    public function getReviewer(): User
    {
        return $this->reviewer;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getBaselineScore(): int
    {
        return $this->baselineScore;
    }

    public function getReviewedScore(): ?int
    {
        return $this->reviewedScore;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function getLastReminderAt(): ?\DateTimeImmutable
    {
        return $this->lastReminderAt;
    }

    public function complete(int $score, ?string $comment): void
    {
        $this->reviewedScore = $score;
        $this->comment = null === $comment || '' === trim($comment) ? null : trim($comment);
        $this->status = 'COMPLETED';
        $this->completedAt = new \DateTimeImmutable();
    }

    public function markReminded(): void
    {
        $this->lastReminderAt = new \DateTimeImmutable();
    }
}
