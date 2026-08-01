<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AssistantProposalRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AssistantProposalRepository::class)]
#[ORM\Table(name: 'assistant_proposals')]
#[ORM\Index(columns: ['organization_id', 'kind', 'status'], name: 'idx_assistant_proposal_tenant')]
class AssistantProposal
{
    public const KINDS = ['MAPPING_SUGGESTION', 'GAP_SUMMARY', 'REPORT_DRAFT', 'QUESTION_SUGGESTIONS'];
    public const STATUSES = ['PENDING', 'APPROVED', 'REJECTED'];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private Organization $organization;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private User $requestedBy;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')] private ?User $validatedBy = null;
    #[ORM\Column(length: 40)] private string $kind;
    /** @var array<string, mixed> */ #[ORM\Column(type: 'json')] private array $requestData;
    /** @var array<string, mixed> */ #[ORM\Column(type: 'json')] private array $proposal;
    /** @var list<array{type: string, id: int, label: string}> */ #[ORM\Column(type: 'json')] private array $sources;
    #[ORM\Column] private float $sourceCoverage;
    #[ORM\Column(length: 20)] private string $status = 'PENDING';
    #[ORM\Column(type: 'text', nullable: true)] private ?string $validationComment = null;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $validatedAt = null;
    /**
     * @param array<string, mixed>                              $requestData
     * @param array<string, mixed>                              $proposal
     * @param list<array{type: string, id: int, label: string}> $sources
     */
    public function __construct(Organization $organization, User $requestedBy, string $kind, array $requestData, array $proposal, array $sources)
    {
        if (!in_array($kind, self::KINDS, true) || [] === $proposal) {
            throw new \InvalidArgumentException('Proposition d’assistant invalide.');
        }
        if ($requestedBy->getOrganization() !== $organization) {
            throw new \InvalidArgumentException('Le demandeur appartient à une autre organisation.');
        }
        $this->organization = $organization;
        $this->requestedBy = $requestedBy;
        $this->kind = $kind;
        $this->requestData = $requestData;
        $this->proposal = $proposal;
        $this->sources = $sources;
        $this->sourceCoverage = [] === $sources ? 0.0 : 1.0;
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

    public function getRequestedBy(): User
    {
        return $this->requestedBy;
    }

    public function getValidatedBy(): ?User
    {
        return $this->validatedBy;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    /** @return array<string, mixed> */
    public function getRequestData(): array
    {
        return $this->requestData;
    }

    /** @return array<string, mixed> */
    public function getProposal(): array
    {
        return $this->proposal;
    }

    /** @return list<array{type: string, id: int, label: string}> */
    public function getSources(): array
    {
        return $this->sources;
    }

    public function getSourceCoverage(): float
    {
        return $this->sourceCoverage;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getValidationComment(): ?string
    {
        return $this->validationComment;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getValidatedAt(): ?\DateTimeImmutable
    {
        return $this->validatedAt;
    }

    public function validate(User $validator, string $decision, string $comment): void
    {
        if ('PENDING' !== $this->status || !in_array($decision, ['APPROVED', 'REJECTED'], true) || '' === trim($comment)) {
            throw new \LogicException('Validation humaine invalide.');
        }
        if ($validator === $this->requestedBy) {
            throw new \LogicException('Le demandeur ne peut pas valider sa propre proposition.');
        }
        if ($validator->getOrganization() !== $this->organization) {
            throw new \InvalidArgumentException('Le validateur appartient à une autre organisation.');
        }
        $this->status = $decision;
        $this->validationComment = trim($comment);
        $this->validatedBy = $validator;
        $this->validatedAt = new \DateTimeImmutable();
    }
}
