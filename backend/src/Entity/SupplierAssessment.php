<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'supplier_assessments')]
class SupplierAssessment
{
    public const STATUSES = ['DRAFT', 'SENT', 'IN_PROGRESS', 'SUBMITTED', 'REVIEWED', 'EXPIRED'];
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne(inversedBy: 'assessments')] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private ThirdParty $thirdParty;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private User $reviewer;
    #[ORM\Column(length: 200)] private string $title;
    #[ORM\Column] private int $questionnaireVersion;
    /** @var list<array{id: string, label: string, weight: int}> */ #[ORM\Column(type: 'json')] private array $questions;
    /** @var array<string, mixed> */ #[ORM\Column(type: 'json')] private array $responses = [];
    /** @var list<string> */ #[ORM\Column(type: 'json')] private array $evidence = [];
    #[ORM\Column(length: 20)] private string $status = 'DRAFT';
    #[ORM\Column(length: 64, unique: true)] private string $publicToken;
    #[ORM\Column] private \DateTimeImmutable $expiresAt;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $submittedAt = null;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $reviewedAt = null;
    #[ORM\Column] private int $score = 0;
    #[ORM\Column(type: 'text', nullable: true)] private ?string $reviewComment = null;
    /** @param list<array{id: string, label: string, weight: int}> $questions */
    public function __construct(ThirdParty $thirdParty, User $reviewer, string $title, int $version, array $questions, \DateTimeImmutable $expiresAt)
    {
        if ('' === trim($title) || $version < 1 || [] === $questions || $expiresAt <= new \DateTimeImmutable()) {
            throw new \InvalidArgumentException('Campagne fournisseur invalide.');
        } $this->thirdParty = $thirdParty;
        $this->reviewer = $reviewer;
        $this->title = trim($title);
        $this->questionnaireVersion = $version;
        $this->questions = $questions;
        $this->expiresAt = $expiresAt;
        $this->publicToken = bin2hex(random_bytes(32));
        $thirdParty->addAssessment($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getThirdParty(): ThirdParty
    {
        return $this->thirdParty;
    }

    public function getReviewer(): User
    {
        return $this->reviewer;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getQuestionnaireVersion(): int
    {
        return $this->questionnaireVersion;
    }

    /** @return list<array{id: string, label: string, weight: int}> */
    public function getQuestions(): array
    {
        return $this->questions;
    }

    /** @return array<string, mixed> */
    public function getResponses(): array
    {
        return $this->responses;
    }

    /** @return list<string> */
    public function getEvidence(): array
    {
        return $this->evidence;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getPublicToken(): string
    {
        return $this->publicToken;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function getReviewedAt(): ?\DateTimeImmutable
    {
        return $this->reviewedAt;
    }

    public function getScore(): int
    {
        return $this->score;
    }

    public function getReviewComment(): ?string
    {
        return $this->reviewComment;
    }

    /**
     * @param array<string, mixed> $responses
     * @param list<string>         $evidence
     */
    public function submit(array $responses, array $evidence): void
    {
        if ($this->expiresAt < new \DateTimeImmutable()) {
            $this->status = 'EXPIRED';
            throw new \LogicException('Ce questionnaire a expiré.');
        } foreach ($this->questions as $question) {
            if (!array_key_exists($question['id'], $responses)) {
                throw new \InvalidArgumentException('Toutes les questions sont obligatoires.');
            }
        } $this->responses = $responses;
        $this->evidence = $evidence;
        $this->status = 'SUBMITTED';
        $this->submittedAt = new \DateTimeImmutable();
    }

    public function review(int $score, string $comment): void
    {
        if ('SUBMITTED' !== $this->status || $score < 0 || $score > 100 || '' === trim($comment)) {
            throw new \LogicException('Revue fournisseur invalide.');
        } $this->score = $score;
        $this->reviewComment = trim($comment);
        $this->status = 'REVIEWED';
        $this->reviewedAt = new \DateTimeImmutable();
        $this->thirdParty->setCyberScore($score);
    }
}
