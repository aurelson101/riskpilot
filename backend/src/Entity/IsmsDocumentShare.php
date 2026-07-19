<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'isms_document_shares')]
#[ORM\UniqueConstraint(name: 'uniq_isms_share_token', columns: ['token_hash'])]
class IsmsDocumentShare
{
    #[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne(inversedBy: 'shares')] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private IsmsDocument $document;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')] private User $createdBy;
    #[ORM\Column(length: 64)] private string $tokenHash;
    #[ORM\Column(nullable: true)] private ?string $passwordHash;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $expiresAt;
    #[ORM\Column] private bool $enabled = true;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $lastAccessedAt = null;
    #[ORM\Column] private int $accessCount = 0;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    public function __construct(IsmsDocument $document, User $createdBy, string $tokenHash, ?string $passwordHash, ?\DateTimeImmutable $expiresAt)
    {
        $this->document = $document;
        $this->createdBy = $createdBy;
        $this->tokenHash = $tokenHash;
        $this->passwordHash = $passwordHash;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDocument(): IsmsDocument
    {
        return $this->document;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function hasPassword(): bool
    {
        return null !== $this->passwordHash;
    }

    public function getAccessCount(): int
    {
        return $this->accessCount;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isAvailable(): bool
    {
        return $this->enabled && (null === $this->expiresAt || $this->expiresAt > new \DateTimeImmutable());
    }

    public function verifiesPassword(?string $password): bool
    {
        return null === $this->passwordHash || (null !== $password && password_verify($password, $this->passwordHash));
    }

    public function revoke(): void
    {
        $this->enabled = false;
    }

    public function recordAccess(): void
    {
        ++$this->accessCount;
        $this->lastAccessedAt = new \DateTimeImmutable();
    }
}
