<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PasswordResetTokenRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PasswordResetTokenRepository::class)]
#[ORM\Table(name: 'password_reset_tokens')]
#[ORM\UniqueConstraint(name: 'uniq_password_reset_token', columns: ['token_hash'])]
class PasswordResetToken
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;
    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')] private User $user;
    #[ORM\Column(length: 64)] private string $tokenHash;
    #[ORM\Column] private \DateTimeImmutable $expiresAt;
    #[ORM\Column] private \DateTimeImmutable $createdAt;
    #[ORM\Column(nullable: true)] private ?\DateTimeImmutable $usedAt = null;
    public function __construct(User $user, string $tokenHash)
    {
        $this->user = $user;
        $this->tokenHash = $tokenHash;
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $this->createdAt->modify('+30 minutes');
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function isValid(): bool
    {
        return null === $this->usedAt && $this->expiresAt > new \DateTimeImmutable();
    }

    public function consume(): void
    {
        $this->usedAt = new \DateTimeImmutable();
    }
}
