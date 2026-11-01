<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ExecutiveGovernanceRecordRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ExecutiveGovernanceRecordRepository::class)] #[ORM\Table(name: 'executive_governance_records')]
class ExecutiveGovernanceRecord
{
    public const TYPES = ['OBJECTIVE', 'INDICATOR', 'MANAGEMENT_REVIEW', 'FINANCIAL_SCENARIO', 'INVESTMENT_CASE', 'CONTEXT_ISSUE', 'INTERESTED_PARTY', 'COMPETENCE_RECORD', 'AWARENESS_CAMPAIGN'];
    /** Entrées obligatoires d'une revue de direction approuvée (ISO/IEC 27001 et 42001, clause 9.3.2). */
    public const REVIEW_INPUTS = ['previousActions', 'contextChanges', 'auditResults', 'nonconformities', 'monitoringResults', 'interestedPartyFeedback', 'riskResults', 'improvementOpportunities'];
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Organization $organization;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private User $owner;
    #[ORM\Column(length: 30)] private string $type;
    #[ORM\Column(length: 220)] private string $title;
    /** @var array<string, mixed> */ #[ORM\Column(type: 'json')] private array $details;
    #[ORM\Column(length: 20)] private string $status;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $reviewAt;
    #[ORM\Column] private \DateTimeImmutable $updatedAt;
    /** @param array<string, mixed> $details */
    public function __construct(Organization $organization, User $owner, string $type, string $title, array $details, string $status, ?\DateTimeImmutable $reviewAt)
    {
        $this->organization = $organization;
        $this->owner = $owner;
        $this->type = $type;
        $this->status = 'DRAFT';
        $this->update($title, $details, $status, $reviewAt, $owner);
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

    public function getType(): string
    {
        return $this->type;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    /** @return array<string, mixed> */
    public function getDetails(): array
    {
        return $this->details;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getReviewAt(): ?\DateTimeImmutable
    {
        return $this->reviewAt;
    }

    /** @param array<string, mixed> $details */
    public function update(string $title, array $details, string $status, ?\DateTimeImmutable $reviewAt, User $owner): void
    {
        if (!in_array($this->type, self::TYPES, true) || '' === trim($title) || !in_array($status, ['DRAFT', 'ACTIVE', 'ON_TRACK', 'AT_RISK', 'COMPLETED', 'APPROVED'], true)) {
            throw new \InvalidArgumentException('Élément de pilotage invalide.');
        } $rules = ['OBJECTIVE' => ['target', 'measure', 'deadline'], 'INDICATOR' => ['kind', 'value', 'target', 'warningThreshold', 'criticalThreshold', 'period'], 'MANAGEMENT_REVIEW' => ['date', 'participants', 'inputs', 'decisions', 'actions'], 'FINANCIAL_SCENARIO' => ['frequencyMin', 'frequencyMax', 'lossMin', 'lossMostLikely', 'lossMax', 'currency'], 'INVESTMENT_CASE' => ['cost', 'effortDays', 'expectedLossReduction', 'roi', 'scenario'], 'CONTEXT_ISSUE' => ['kind', 'description', 'impact'], 'INTERESTED_PARTY' => ['party', 'expectations', 'legalRequirement'], 'COMPETENCE_RECORD' => ['subject', 'requiredCompetence', 'evidence'], 'AWARENESS_CAMPAIGN' => ['audience', 'topic', 'date', 'completionRate']];
        foreach ($rules[$this->type] as $key) {
            if (!array_key_exists($key, $details)) {
                throw new \InvalidArgumentException(sprintf('Le champ %s est requis pour %s.', $key, $this->type));
            }
        } if ('FINANCIAL_SCENARIO' === $this->type && ((float) $details['frequencyMin'] < 0 || (float) $details['frequencyMin'] > (float) $details['frequencyMax'] || (float) $details['lossMin'] < 0 || (float) $details['lossMin'] > (float) $details['lossMostLikely'] || (float) $details['lossMostLikely'] > (float) $details['lossMax'] || (float) ($details['indirectLossFactor'] ?? 0) < 0)) {
            throw new \InvalidArgumentException('Les fréquences et pertes doivent être positives et ordonnées.');
        } if ('FINANCIAL_SCENARIO' === $this->type && 'APPROVED' === $status && ('' === trim((string) ($details['modelVersion'] ?? '')) || true !== ($details['financeApproval']['approved'] ?? false))) {
            throw new \InvalidArgumentException('La version du modèle et l’approbation finance sont obligatoires.');
        } if ('CONTEXT_ISSUE' === $this->type && !in_array($details['kind'], ['INTERNAL', 'EXTERNAL'], true)) {
            throw new \InvalidArgumentException('Un enjeu de contexte est interne ou externe.');
        } if ('AWARENESS_CAMPAIGN' === $this->type && (!is_numeric($details['completionRate']) || $details['completionRate'] < 0 || $details['completionRate'] > 100)) {
            throw new \InvalidArgumentException('Le taux de réalisation est un pourcentage entre 0 et 100.');
        } if ('MANAGEMENT_REVIEW' === $this->type && in_array($status, ['APPROVED', 'COMPLETED'], true) && $this->status !== $status) {
            $inputs = is_array($details['inputs']) ? $details['inputs'] : [];
            $missing = array_values(array_filter(self::REVIEW_INPUTS, static fn (string $key): bool => !isset($inputs[$key]) || '' === trim(is_array($inputs[$key]) ? implode('', array_map('strval', $inputs[$key])) : (string) $inputs[$key])));
            if ([] !== $missing) {
                throw new \InvalidArgumentException('Entrées de revue de direction manquantes (9.3.2) : '.implode(', ', $missing).'.');
            }
        } $this->title = trim($title);
        $this->details = $details;
        $this->status = $status;
        $this->reviewAt = $reviewAt;
        $this->owner = $owner;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
