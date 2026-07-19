<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AuthSession;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AuthSession> */
final class AuthSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuthSession::class);
    }

    public function findByRefreshToken(string $token): ?AuthSession
    {
        return $this->findOneBy(['refreshTokenHash' => hash('sha256', $token)]);
    }

    public function findActiveByPublicId(string $publicId): ?AuthSession
    {
        $session = $this->findOneBy(['publicId' => $publicId]);

        return $session?->isActive() ? $session : null;
    }

    /** @return list<AuthSession> */
    public function findForUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['lastUsedAt' => 'DESC']);
    }

    public function revokeAll(User $user, ?AuthSession $except = null): void
    {
        foreach ($this->findForUser($user) as $session) {
            if ($session !== $except) {
                $session->revoke();
            }
        }
    }
}
