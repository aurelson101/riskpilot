<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PasswordResetToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PasswordResetToken> */
final class PasswordResetTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PasswordResetToken::class);
    }

    public function findByRawToken(string $token): ?PasswordResetToken
    {
        return $this->findOneBy(['tokenHash' => hash('sha256', $token)]);
    }

    public function invalidateFor(User $user): void
    {
        foreach ($this->findBy(['user' => $user, 'usedAt' => null]) as $token) {
            $token->consume();
        }
    }
}
