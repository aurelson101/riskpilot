<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\RiskAcceptanceRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RiskAcceptanceRepository::class)]
#[ORM\Table(name: 'risk_acceptances')]
class RiskAcceptance
{
    public const STATUSES = ['PENDING', 'APPROVED', 'REJECTED', 'REVOKED', 'EXPIRED'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Organization $organization;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private RiskScenario $risk;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private User $requestedBy;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')] private ?User $decidedBy = null;
    #[ORM\Column(type: 'text')] private string $justification;
    #[ORM\Column(length: 200)] private string $authority;
    #[ORM\Column(length: 20)] private string $status = 'PENDING';
    #[ORM\Column] private \DateTimeImmutable $expiresAt;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $decidedAt = null;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $decisionComment = null;
    #[ORM\Column(length: 255, nullable: true)] private ?string $evidenceReference = null;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    public function __construct(RiskScenario $risk, User $requester, string $justification, string $authority, \DateTimeImmutable $expiresAt, ?string $evidenceReference)
    {
        $this->risk = $risk;
        $this->organization = $risk->getOrganization();
        $this->requestedBy = $requester;
        $this->justification = trim($justification);
        $this->authority = trim($authority);
        $this->expiresAt = $expiresAt;
        $this->evidenceReference = null === $evidenceReference || '' === trim($evidenceReference) ? null : trim($evidenceReference);
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

    public function getRisk(): RiskScenario
    {
        return $this->risk;
    }

    public function getRequestedBy(): User
    {
        return $this->requestedBy;
    }

    public function getDecidedBy(): ?User
    {
        return $this->decidedBy;
    }

    public function getJustification(): string
    {
        return $this->justification;
    }

    public function getAuthority(): string
    {
        return $this->authority;
    }

    public function getStoredStatus(): string
    {
        return $this->status;
    }

    public function getStatus(): string
    {
        return 'APPROVED' === $this->status && $this->expiresAt < new \DateTimeImmutable() ? 'EXPIRED' : $this->status;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function getDecisionComment(): ?string
    {
        return $this->decisionComment;
    }

    public function getEvidenceReference(): ?string
    {
        return $this->evidenceReference;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function decide(string $status, User $decider, ?string $comment): void
    {
        if ('PENDING' !== $this->status || !in_array($status, ['APPROVED', 'REJECTED'], true)) {
            throw new \LogicException('Cette demande ne peut plus être décidée.');
        }
        if ($decider === $this->requestedBy) {
            throw new \LogicException('Le demandeur ne peut pas décider sa propre acceptation de risque.');
        }
        $this->status = $status;
        $this->decidedBy = $decider;
        $this->decidedAt = new \DateTimeImmutable();
        $this->decisionComment = null === $comment || '' === trim($comment) ? null : trim($comment);
        if ('APPROVED' === $status) {
            $this->risk->setStatus('ACCEPTED')->setTreatmentDecision('ACCEPT');
        }
    }

    public function revoke(User $decider, ?string $comment): void
    {
        if ('APPROVED' !== $this->status) {
            throw new \LogicException('Seule une acceptation approuvée peut être révoquée.');
        }
        $this->status = 'REVOKED';
        $this->decidedBy = $decider;
        $this->decidedAt = new \DateTimeImmutable();
        $this->decisionComment = null === $comment || '' === trim($comment) ? 'Acceptation révoquée' : trim($comment);
        $this->risk->setStatus('IN_REVIEW');
    }

    public function expire(): void
    {
        if ('APPROVED' === $this->status && $this->expiresAt < new \DateTimeImmutable()) {
            $this->status = 'EXPIRED';
            $this->decisionComment = trim(($this->decisionComment ?? '').' Acceptation arrivée à expiration.');
            $this->risk->setStatus('IN_REVIEW');
        }
    }
}
