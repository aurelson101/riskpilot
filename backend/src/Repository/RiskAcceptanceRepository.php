<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RiskAcceptance;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<RiskAcceptance> */
final class RiskAcceptanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RiskAcceptance::class);
    }

    /** @return list<RiskAcceptance> */
    public function findVisibleTo(User $actor): array
    {
        return $this->findBy(['organization' => $actor->getOrganization()], ['createdAt' => 'DESC']);
    }

    public function findOneVisibleTo(int $id, User $actor): ?RiskAcceptance
    {
        return $this->findOneBy(['id' => $id, 'organization' => $actor->getOrganization()]);
    }

    public function hasActiveForRisk(int $riskId): bool
    {
        return null !== $this->createQueryBuilder('a')->select('a.id')->where('IDENTITY(a.risk) = :risk')->andWhere('a.status IN (:statuses)')->andWhere('a.expiresAt > :now')->setParameter('risk', $riskId)->setParameter('statuses', ['PENDING', 'APPROVED'])->setParameter('now', new \DateTimeImmutable())->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    public function hasApprovedForRisk(int $riskId): bool
    {
        return null !== $this->createQueryBuilder('a')->select('a.id')->where('IDENTITY(a.risk) = :risk')->andWhere('a.status = :status')->andWhere('a.expiresAt > :now')->setParameter('risk', $riskId)->setParameter('status', 'APPROVED')->setParameter('now', new \DateTimeImmutable())->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /** @return list<RiskAcceptance> */
    public function findExpiredApprovals(): array
    {
        return $this->createQueryBuilder('a')->where('a.status = :status')->andWhere('a.expiresAt < :now')->setParameter('status', 'APPROVED')->setParameter('now', new \DateTimeImmutable())->getQuery()->getResult();
    }
}
