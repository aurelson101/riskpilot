<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AiImpactAssessmentRepository;
use Doctrine\ORM\Mapping as ORM;

/** Évaluation d'impact d'un système d'IA (ISO/IEC 42001 6.1.4 et A.5). Une évaluation approuvée est immuable. */
#[ORM\Entity(repositoryClass: AiImpactAssessmentRepository::class)]
#[ORM\Table(name: 'ai_impact_assessments')]
class AiImpactAssessment
{
    public const STATUSES = ['DRAFT', 'IN_REVIEW', 'APPROVED'];
    public const RESIDUAL_RISKS = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Organization $organization;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private AiSystem $system;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private User $author;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')] private ?User $reviewer = null;
    #[ORM\Column(length: 20)] private string $status = 'DRAFT';
    #[ORM\Column(type: 'text')] private string $affectedGroups = '';
    #[ORM\Column(type: 'text')] private string $benefits = '';
    #[ORM\Column(type: 'text')] private string $harms = '';
    #[ORM\Column(type: 'text')] private string $mitigations = '';
    #[ORM\Column(length: 20)] private string $residualRisk = 'MEDIUM';
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $reviewAt = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $approvedAt = null;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    #[ORM\Column] private \DateTimeImmutable $updatedAt;
    public function __construct(AiSystem $system, User $author)
    {
        $this->organization = $system->getOrganization();
        $this->system = $system;
        $this->author = $author;
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function update(string $affectedGroups, string $benefits, string $harms, string $mitigations, string $residualRisk, ?\DateTimeImmutable $reviewAt): void
    {
        if ('APPROVED' === $this->status) {
            throw new \InvalidArgumentException('Une évaluation approuvée est immuable : créez-en une nouvelle version.');
        }
        if (!in_array($residualRisk, self::RESIDUAL_RISKS, true)) {
            throw new \InvalidArgumentException('Risque résiduel invalide.');
        }
        $this->affectedGroups = mb_substr(trim($affectedGroups), 0, 5000);
        $this->benefits = mb_substr(trim($benefits), 0, 5000);
        $this->harms = mb_substr(trim($harms), 0, 5000);
        $this->mitigations = mb_substr(trim($mitigations), 0, 5000);
        $this->residualRisk = $residualRisk;
        $this->reviewAt = $reviewAt;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function submit(): void
    {
        if ('DRAFT' !== $this->status) {
            throw new \InvalidArgumentException('Seul un brouillon peut être soumis à relecture.');
        }
        foreach (['affectedGroups' => $this->affectedGroups, 'benefits' => $this->benefits, 'harms' => $this->harms, 'mitigations' => $this->mitigations] as $field => $value) {
            if ('' === $value) {
                throw new \InvalidArgumentException(sprintf('Le champ %s est obligatoire avant la relecture.', $field));
            }
        }
        $this->status = 'IN_REVIEW';
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function approve(User $reviewer): void
    {
        if ('IN_REVIEW' !== $this->status) {
            throw new \InvalidArgumentException('Seule une évaluation en relecture peut être approuvée.');
        }
        if ($reviewer === $this->author || $reviewer->getId() === $this->author->getId()) {
            throw new \InvalidArgumentException('L’approbateur doit être distinct de l’auteur.');
        }
        if ('PROHIBITED' === $this->system->getRiskLevel()) {
            throw new \InvalidArgumentException('Un système de risque inacceptable ne peut pas être approuvé.');
        }
        $this->reviewer = $reviewer;
        $this->status = 'APPROVED';
        $this->approvedAt = new \DateTimeImmutable();
        $this->updatedAt = $this->approvedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganization(): Organization
    {
        return $this->organization;
    }

    public function getSystem(): AiSystem
    {
        return $this->system;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function getReviewer(): ?User
    {
        return $this->reviewer;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getAffectedGroups(): string
    {
        return $this->affectedGroups;
    }

    public function getBenefits(): string
    {
        return $this->benefits;
    }

    public function getHarms(): string
    {
        return $this->harms;
    }

    public function getMitigations(): string
    {
        return $this->mitigations;
    }

    public function getResidualRisk(): string
    {
        return $this->residualRisk;
    }

    public function getReviewAt(): ?\DateTimeImmutable
    {
        return $this->reviewAt;
    }

    public function getApprovedAt(): ?\DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
