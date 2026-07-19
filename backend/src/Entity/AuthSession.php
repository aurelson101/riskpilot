<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AuthSessionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AuthSessionRepository::class)]
#[ORM\Table(name: 'auth_sessions')]
#[ORM\UniqueConstraint(name: 'uniq_auth_session_public_id', columns: ['public_id'])]
#[ORM\UniqueConstraint(name: 'uniq_auth_session_token', columns: ['refresh_token_hash'])]
class AuthSession
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $publicId;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 64)]
    private string $refreshTokenHash;

    #[ORM\Column(length: 255)]
    private string $userAgent;

    #[ORM\Column(length: 64)]
    private string $ipAddress;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastUsedAt;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    public function __construct(User $user, string $refreshTokenHash, string $userAgent, string $ipAddress)
    {
        $this->publicId = bin2hex(random_bytes(16));
        $this->user = $user;
        $this->refreshTokenHash = $refreshTokenHash;
        $this->userAgent = mb_substr($userAgent, 0, 255);
        $this->ipAddress = mb_substr($ipAddress, 0, 64);
        $this->createdAt = new \DateTimeImmutable();
        $this->lastUsedAt = $this->createdAt;
        $this->expiresAt = $this->createdAt->modify('+30 days');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublicId(): string
    {
        return $this->publicId;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    public function getIpAddress(): string
    {
        return $this->ipAddress;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): \DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function isActive(): bool
    {
        return null === $this->revokedAt && $this->expiresAt > new \DateTimeImmutable() && User::STATUS_ACTIVE === $this->user->getStatus();
    }

    public function rotate(string $refreshTokenHash): void
    {
        $this->refreshTokenHash = $refreshTokenHash;
        $this->lastUsedAt = new \DateTimeImmutable();
        $this->expiresAt = $this->lastUsedAt->modify('+30 days');
    }

    public function revoke(): void
    {
        $this->revokedAt ??= new \DateTimeImmutable();
    }
}
